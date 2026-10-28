"""Embeddings con Gemini (IA-4) para el RAG de NEXO-UNAC.

Modelo: gemini-embedding-001 con outputDimensionality=768 (coleccion Qdrant).
Configurable con EMBED_MODEL / EMBED_DIM en el .env del repo.
Cache local por hash de texto para no gastar cuota en re-ejecuciones.

La clave viaja en el header `x-goog-api-key` (nunca en la URL), para que no
aparezca en los mensajes de error de `requests`.

El progreso se escribe siempre en **stderr** para no contaminar salidas JSON
(`retrieve.py --json`) cuando hay cache miss.
"""
from __future__ import annotations

import hashlib
import json
import sys
import time
from dataclasses import dataclass
from pathlib import Path

from . import env

DEFAULT_MODEL = "gemini-embedding-001"
DEFAULT_DIM = 768
BATCH_SIZE = 10  # textos unicos por request: mantiene el TPM bajo en free tier
MAX_RETRIES = 5
RETRY_STATUS = {429, 500, 502, 503, 504}
_API = "https://generativelanguage.googleapis.com/v1beta/models/{model}:batchEmbedContents"


@dataclass(frozen=True)
class EmbeddingStats:
    """Conteo exacto de textos pedidos, unicos, cacheados y nuevos."""

    requested: int
    unique: int
    cached: int
    new: int


def model_name() -> str:
    return env.get("EMBED_MODEL", DEFAULT_MODEL)


def dimension() -> int:
    return int(env.get("EMBED_DIM", str(DEFAULT_DIM)))


def _cache_dir() -> Path:
    return env.repo_root() / ".cache" / "embeddings"


def _cache_key(text: str, model: str, output_dim: int, task_type: str) -> str:
    payload = f"{model}|{output_dim}|{task_type}|{text}"
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def _cache_read(key: str, output_dim: int) -> list[float] | None:
    path = _cache_dir() / f"{key}.json"
    if not path.is_file():
        return None
    try:
        values = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None
    if isinstance(values, list) and len(values) == output_dim:
        return values
    return None


def _cache_write(key: str, vector: list[float]) -> None:
    """Mejor esfuerzo: sin permisos/espacio la cache no debe tumbar el ingest."""
    try:
        cache = _cache_dir()
        cache.mkdir(parents=True, exist_ok=True)
        (cache / f"{key}.json").write_text(json.dumps(vector), encoding="utf-8")
    except OSError:
        pass


def _retry_delay(response, attempt: int) -> float:
    try:
        details = response.json().get("error", {}).get("details", [])
        for detail in details:
            delay = detail.get("retryDelay")
            if delay:
                return min(120.0, float(str(delay).rstrip("s")) + 1)
    except (ValueError, AttributeError):
        pass
    return min(120.0, 15.0 * (attempt + 1))


def _post_with_retry(
    url: str,
    payload: dict,
    headers: dict,
    timeout: float = 120,
    retries: int = MAX_RETRIES,
) -> dict:
    import requests

    last_error = ""
    for attempt in range(retries):
        last = attempt + 1 >= retries
        try:
            response = requests.post(url, json=payload, headers=headers, timeout=timeout)
        except requests.RequestException as exc:
            # No incluir exc: su texto puede contener la URL de la peticion.
            last_error = f"{type(exc).__name__} (red/timeout)"
            if not last:
                time.sleep(min(30.0, 2.0 ** (attempt + 1)))
            continue

        if response.status_code == 200:
            try:
                return response.json()
            except ValueError as exc:
                raise RuntimeError("Gemini embeddings: respuesta 200 no-JSON") from exc

        last_error = f"HTTP {response.status_code}: {response.text[:300]}"
        if response.status_code in RETRY_STATUS and not last:
            time.sleep(_retry_delay(response, attempt))
            continue
        break

    raise RuntimeError(f"Gemini embeddings: {last_error}")


def embed_texts(
    texts: list[str],
    *,
    task_type: str = "RETRIEVAL_DOCUMENT",
    model: str | None = None,
    output_dim: int | None = None,
    return_stats: bool = False,
    timeout: float = 120,
    retries: int = MAX_RETRIES,
) -> list[list[float]] | tuple[list[list[float]], EmbeddingStats]:
    """Devuelve un vector por texto (mismo orden). Usa cache local cuando puede.

    Los textos repetidos se embeben una sola vez y se reutiliza el vector.
    Con `return_stats=True` devuelve `(vectores, EmbeddingStats)`.
    `timeout`/`retries` aplican a cada request (la consulta usa valores cortos).
    """
    if not texts:
        empty: list[list[float]] = []
        if return_stats:
            return empty, EmbeddingStats(0, 0, 0, 0)
        return empty

    model = model or model_name()
    output_dim = int(output_dim or dimension())

    # Agrupa indices por cache key: textos identicos comparten un solo vector.
    groups: dict[str, list[int]] = {}
    for index, text in enumerate(texts):
        cache_key = _cache_key(text, model, output_dim, task_type)
        groups.setdefault(cache_key, []).append(index)

    results: list[list[float] | None] = [None] * len(texts)
    missing: list[tuple[str, list[int]]] = []
    cached = 0

    for cache_key, indexes in groups.items():
        vector = _cache_read(cache_key, output_dim)
        if vector is None:
            missing.append((cache_key, indexes))
        else:
            cached += 1
            for index in indexes:
                results[index] = vector

    if missing:
        key = env.get("GEMINI_API_KEY")
        if not key:
            raise RuntimeError("Falta GEMINI_API_KEY en el .env del repo")

        url = _API.format(model=model)
        headers = {"x-goog-api-key": key}
        print(
            f"  embeddings: {len(missing)} unicos por calcular, "
            f"{cached} unicos en cache",
            file=sys.stderr,
            flush=True,
        )

        for start in range(0, len(missing), BATCH_SIZE):
            batch = missing[start : start + BATCH_SIZE]
            payload = {
                "requests": [
                    {
                        "model": f"models/{model}",
                        "content": {"parts": [{"text": texts[indexes[0]]}]},
                        "taskType": task_type,
                        "outputDimensionality": output_dim,
                    }
                    for _, indexes in batch
                ]
            }

            data = _post_with_retry(url, payload, headers, timeout, retries)
            embeddings = data.get("embeddings") or []
            if len(embeddings) != len(batch):
                raise RuntimeError(
                    f"Gemini embeddings: se pidieron {len(batch)} y llegaron "
                    f"{len(embeddings)}"
                )

            for (cache_key, indexes), embedding in zip(batch, embeddings):
                vector = embedding.get("values") or []
                if len(vector) != output_dim:
                    raise RuntimeError(
                        f"Gemini embeddings: dimension {len(vector)} != {output_dim}"
                    )
                for index in indexes:
                    results[index] = vector
                _cache_write(cache_key, vector)

            done = min(start + len(batch), len(missing))
            print(
                f"  embeddings: {done}/{len(missing)} unicos",
                file=sys.stderr,
                flush=True,
            )
            if start + BATCH_SIZE < len(missing):
                time.sleep(1.0)

    if any(vector is None for vector in results):
        raise RuntimeError("Gemini embeddings: quedaron textos sin vector")

    vectors = [vector for vector in results if vector is not None]
    stats = EmbeddingStats(
        requested=len(texts),
        unique=len(groups),
        cached=cached,
        new=len(missing),
    )
    return (vectors, stats) if return_stats else vectors


def embed_query(
    text: str, *, model: str | None = None, output_dim: int | None = None
) -> list[float]:
    if not text or not text.strip():
        raise ValueError("La consulta no puede estar vacia")
    # Interactivo (chat): ~15s y 1 reintento, no los 120s x 5 del ingest.
    vectors = embed_texts(
        [text],
        task_type="RETRIEVAL_QUERY",
        model=model,
        output_dim=output_dim,
        timeout=15,
        retries=2,
    )
    return vectors[0]
