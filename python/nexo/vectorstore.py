"""Cliente REST minimo para Qdrant (IA-5), sin SDK.

Solo cubre lo que necesita el pipeline RAG: crear/validar la coleccion,
indices de payload, upsert por lotes, scroll/delete y busqueda con filtros.

Decisiones:
- `point_id` es UUID5 determinista de `"nexo:{doc_id}:{chunk_index}"`, de modo
  que reingestar el mismo material actualiza los puntos en vez de duplicarlos.
- El payload lleva `chunk_id` entero (el indice del chunk dentro del doc) para
  que el indice Qdrant sea integer; la trazabilidad legible va en `chunk_key`
  ("doc_id-chunk_index"). MySQL sigue siendo la fuente de verdad del texto.
- `topic_norm` normaliza el tema (NFKC + espacios + casefold) para que el
  filtro no dependa de mayusculas/espacios. `class_date` se valida como ISO.
- La API key, si existe, viaja en el header `api-key` y nunca se loguea.
"""
from __future__ import annotations

import datetime as _dt
import time
import unicodedata
import urllib.parse
import uuid
from typing import Any, TYPE_CHECKING

from . import env

if TYPE_CHECKING:  # evita importar tiktoken/chunking en tiempo de ejecucion
    from .chunking import Chunk

DEFAULT_COLLECTION = env.get("QDRANT_COLLECTION", "nexo_docs")
UPSERT_BATCH = 64
SCROLL_BATCH = 256
DELETE_BATCH = 256
MAX_RETRIES = 3
RETRY_STATUS = {429, 500, 502, 503, 504}

# Esquema de indices: integer para ids numericos, keyword para filtros de texto.
PAYLOAD_INDEXES: dict[str, str] = {
    "chunk_id": "integer",
    "doc_id": "integer",
    "curso_id": "integer",
    "class_date": "keyword",
    "topic": "keyword",
    "topic_norm": "keyword",
}

_DISTANCES = {"cosine", "euclid", "dot", "manhattan"}


class QdrantError(RuntimeError):
    """Error de Qdrant con el status HTTP (None si fue error de red)."""

    def __init__(self, message: str, status: int | None = None) -> None:
        super().__init__(message)
        self.status = status


def _sanitize_url(url: str) -> str:
    """Oculta userinfo de la URL en mensajes de error."""
    try:
        parts = urllib.parse.urlsplit(url)
    except ValueError:
        return "<url invalida>"
    if parts.username is None and parts.password is None:
        return url
    host = parts.hostname or ""
    port = f":{parts.port}" if parts.port else ""
    netloc = f"***@{host}{port}"
    return urllib.parse.urlunsplit(
        (parts.scheme, netloc, parts.path, parts.query, parts.fragment)
    )


def point_id(doc_id: int, chunk_index: int) -> str:
    """ID determinista e idempotente para (doc_id, chunk_index)."""
    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"nexo:{int(doc_id)}:{int(chunk_index)}"))


def normalize_topic(value: str | None) -> str:
    """NFKC + espacios colapsados + casefold; seguro para comparar temas."""
    text = unicodedata.normalize("NFKC", value or "")
    return " ".join(text.split()).casefold()


def normalize_class_date(value: str | None) -> str:
    """Valida y normaliza una fecha ISO (YYYY-MM-DD); '' si esta vacia."""
    text = (value or "").strip()
    if not text:
        return ""
    try:
        return _dt.date.fromisoformat(text).isoformat()
    except ValueError as exc:
        raise ValueError(f"class_date invalida '{value}': usa YYYY-MM-DD") from exc


def build_filter(
    *,
    curso_id: int | None = None,
    class_date: str | None = None,
    topic: str | None = None,
    doc_id: int | None = None,
    doc_ids: list[int] | None = None,
) -> dict | None:
    """Filtro Qdrant (`must`) para los campos indexados; None si no hay filtros."""
    must: list[dict[str, Any]] = []
    if curso_id is not None:
        must.append({"key": "curso_id", "match": {"value": int(curso_id)}})
    if doc_id is not None:
        must.append({"key": "doc_id", "match": {"value": int(doc_id)}})
    if doc_ids is not None:
        # Lista vacia = ningun documento permitido (no "sin filtro").
        must.append({"key": "doc_id", "match": {"any": [int(i) for i in doc_ids]}})
    if class_date:
        normalized_date = normalize_class_date(class_date)
        if normalized_date:
            must.append({"key": "class_date", "match": {"value": normalized_date}})
    if topic:
        normalized_topic = normalize_topic(topic)
        if normalized_topic:
            must.append({"key": "topic_norm", "match": {"value": normalized_topic}})
    return {"must": must} if must else None


def payload_for_chunk(
    chunk: "Chunk",
    curso_id: int,
    class_date: str = "",
    topic: str = "",
) -> dict[str, Any]:
    """Payload indexable y autocontenido (para demo/retrieval) de un chunk."""
    source_key = getattr(chunk, "source_key", "") or chunk.source
    return {
        "chunk_id": int(chunk.chunk_index),  # indice integer en Qdrant
        "doc_id": int(chunk.doc_id),
        # doc_id puede superar 2^53: JS debe usar el string, no el Number.
        "doc_id_str": str(int(chunk.doc_id)),
        "curso_id": int(curso_id),
        "class_date": normalize_class_date(class_date),
        "topic": topic or "",
        "topic_norm": normalize_topic(topic),
        "file_path": chunk.source,
        "source": chunk.source,
        "source_key": source_key,
        "location": chunk.location,
        "kind": chunk.kind,
        "text": chunk.text,
        "tokens": int(chunk.tokens),
        "chunk_key": chunk.chunk_id,
    }


class QdrantStore:
    """Cliente HTTP minimo de Qdrant; falla rapido con mensajes accionables."""

    def __init__(
        self,
        url: str | None = None,
        collection: str = DEFAULT_COLLECTION,
        timeout: int = 30,
        api_key: str | None = None,
    ) -> None:
        raw_url = (url or env.get("QDRANT_URL", "http://localhost:6333")).rstrip("/")
        self.url = raw_url
        self.collection = collection
        self.timeout = timeout
        self.api_key = api_key if api_key is not None else env.get("QDRANT_API_KEY")
        self.display_url = _sanitize_url(raw_url)

    # -- transporte ---------------------------------------------------------

    def _headers(self) -> dict[str, str] | None:
        return {"api-key": self.api_key} if self.api_key else None

    def _request(
        self,
        method: str,
        path: str,
        payload: dict | None = None,
        params: dict | None = None,
    ) -> Any:
        import requests

        url = f"{self.url}{path}"
        headers = self._headers()
        last_error = ""
        last_status: int | None = None

        for attempt in range(MAX_RETRIES):
            try:
                response = requests.request(
                    method,
                    url,
                    json=payload,
                    params=params,
                    headers=headers,
                    timeout=self.timeout,
                )
            except requests.RequestException as exc:
                last_error = f"{type(exc).__name__} (red/timeout)"
                if attempt + 1 < MAX_RETRIES:
                    time.sleep(0.5 * (attempt + 1))
                    continue
                raise QdrantError(
                    f"Qdrant no responde en {self.display_url}; "
                    f"corre qdrant-up ({last_error})"
                ) from exc

            if 200 <= response.status_code < 300:
                if not response.content:
                    return None
                try:
                    return response.json()
                except ValueError as exc:
                    body = (response.text or "").strip()[:200]
                    raise QdrantError(
                        f"Qdrant {method} {path} devolvio {response.status_code} "
                        f"con body no-JSON ({body})",
                        response.status_code,
                    ) from exc

            last_status = response.status_code
            detail = (response.text or "").strip()[:300]
            last_error = f"HTTP {response.status_code}: {detail}"
            if response.status_code in RETRY_STATUS and attempt + 1 < MAX_RETRIES:
                retry_after = response.headers.get("Retry-After", "")
                try:
                    delay = min(5.0, max(0.0, float(retry_after)))
                except (TypeError, ValueError):
                    delay = 0.5 * (attempt + 1)
                time.sleep(delay)
                continue

            raise QdrantError(
                f"Qdrant {method} {path} fallo en {self.display_url} ({last_error})",
                response.status_code,
            )

        raise QdrantError(
            f"Qdrant {method} {path} fallo en {self.display_url} ({last_error})",
            last_status,
        )

    # -- coleccion ----------------------------------------------------------

    def collection_info(self) -> dict:
        data = self._request("GET", f"/collections/{self.collection}")
        result = (data or {}).get("result")
        if not isinstance(result, dict):
            raise QdrantError(
                f"Qdrant devolvio una coleccion invalida para '{self.collection}'"
            )
        return result

    def _validate_collection(self, info: dict, size: int, distance: str) -> dict:
        params = (
            ((info.get("config") or {}).get("params") or {})
            .get("vectors")
            or {}
        )
        if not isinstance(params, dict) or "size" not in params:
            # Configuracion con vectores nombrados: no la soportamos a proposito.
            raise QdrantError(
                f"La coleccion '{self.collection}' usa vectores nombrados; "
                "se esperaba un unico vector."
            )
        actual_size = int(params.get("size", 0))
        actual_distance = str(params.get("distance", ""))
        if actual_size != size:
            raise QdrantError(
                f"La coleccion '{self.collection}' tiene size={actual_size} y se "
                f"esperaba {size}; cambia EMBED_DIM o reingesta en otra coleccion."
            )
        if actual_distance.lower() != distance.lower():
            raise QdrantError(
                f"La coleccion '{self.collection}' usa distance={actual_distance} y "
                f"se esperaba {distance}; recrea la coleccion con la distancia correcta."
            )
        return info

    def ensure_collection(self, size: int, distance: str = "Cosine") -> dict:
        """Crea la coleccion si no existe; si existe valida dimension y distancia."""
        size = int(size)
        distance = str(distance)
        if size <= 0:
            raise QdrantError(f"Dimension invalida para la coleccion: {size}")
        if distance.lower() not in _DISTANCES:
            raise QdrantError(f"Distancia no soportada: {distance}")

        try:
            info = self.collection_info()
        except QdrantError as exc:
            if exc.status != 404:
                raise
            try:
                self._request(
                    "PUT",
                    f"/collections/{self.collection}",
                    {"vectors": {"size": size, "distance": distance}},
                )
            except QdrantError as create_exc:
                # 409 = otra corrida la creo al mismo tiempo.
                if create_exc.status != 409:
                    raise
            info = self.collection_info()
        return self._validate_collection(info, size, distance)

    def ensure_payload_indexes(self) -> None:
        """Crea los indices de payload de forma idempotente."""
        for field, schema in PAYLOAD_INDEXES.items():
            try:
                self._request(
                    "PUT",
                    f"/collections/{self.collection}/index",
                    {"field_name": field, "field_schema": schema},
                    {"wait": "true"},
                )
            except QdrantError as exc:
                # 409 = el indice ya existe (carrera entre procesos).
                if exc.status == 409:
                    continue
                raise

    # -- puntos -------------------------------------------------------------

    def upsert(
        self,
        points: list[dict],
        wait: bool = True,
        batch_size: int = UPSERT_BATCH,
    ) -> int:
        """Upsert por lotes; devuelve la cantidad de puntos enviados."""
        if not points:
            return 0
        batch_size = int(batch_size)
        if batch_size <= 0:
            raise QdrantError("batch_size debe ser > 0")
        sent = 0
        for start in range(0, len(points), batch_size):
            batch = points[start : start + batch_size]
            self._request(
                "PUT",
                f"/collections/{self.collection}/points",
                {"points": batch},
                {"wait": "true" if wait else "false"},
            )
            sent += len(batch)
        return sent

    def search(
        self,
        vector: list[float],
        limit: int = 5,
        filters: dict | None = None,
        score_threshold: float | None = None,
    ) -> list[dict]:
        """Busqueda por similitud; devuelve los hits crudos de Qdrant."""
        if not vector:
            raise QdrantError("Vector de busqueda vacio")
        limit = int(limit)
        if limit <= 0:
            raise QdrantError("limit debe ser > 0")
        body: dict[str, Any] = {
            "query": [float(value) for value in vector],
            "limit": limit,
            "with_payload": True,
            "with_vector": False,
        }
        if filters:
            body["filter"] = filters
        if score_threshold is not None:
            body["score_threshold"] = float(score_threshold)

        data = self._request(
            "POST", f"/collections/{self.collection}/points/query", body
        )
        result = (data or {}).get("result")
        if isinstance(result, dict):
            return result.get("points") or []
        if isinstance(result, list):  # compatibilidad defensiva
            return result
        return []

    def scroll(
        self,
        filters: dict | None = None,
        *,
        with_payload: bool | list[str] = True,
        limit: int = SCROLL_BATCH,
    ) -> list[dict]:
        """Lee todos los puntos que matchean el filtro, paginando."""
        limit = int(limit)
        if limit <= 0:
            raise QdrantError("scroll limit debe ser > 0")
        points: list[dict] = []
        offset = None
        seen_offsets: set[Any] = set()
        while True:
            body: dict[str, Any] = {
                "limit": limit,
                "with_payload": with_payload,
                "with_vector": False,
            }
            if filters:
                body["filter"] = filters
            if offset is not None:
                body["offset"] = offset
            data = self._request(
                "POST", f"/collections/{self.collection}/points/scroll", body
            )
            result = (data or {}).get("result") or {}
            batch = result.get("points") or []
            points.extend(batch)
            offset = result.get("next_page_offset")
            if not offset or not batch or offset in seen_offsets:
                break
            seen_offsets.add(offset)
        return points

    def delete_points(self, ids: list[str]) -> int:
        """Borra puntos por id (UUID) en lotes."""
        clean = [str(point_id_value) for point_id_value in ids if point_id_value]
        for start in range(0, len(clean), DELETE_BATCH):
            self._request(
                "POST",
                f"/collections/{self.collection}/points/delete",
                {"points": clean[start : start + DELETE_BATCH]},
                {"wait": "true"},
            )
        return len(clean)

    def delete_by_filter(self, filters: dict) -> None:
        """Borra todos los puntos que matchean el filtro (p. ej. un doc_id)."""
        if not filters:
            raise QdrantError("delete_by_filter requiere un filtro (no borra todo)")
        self._request(
            "POST",
            f"/collections/{self.collection}/points/delete",
            {"filter": filters},
            {"wait": "true"},
        )
