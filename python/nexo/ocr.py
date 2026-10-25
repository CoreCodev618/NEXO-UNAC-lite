"""OCR de imagenes (pizarras, examenes, apuntes) con Gemini Vision.

Usa la misma GEMINI_API_KEY de los embeddings. El modelo es configurable:
GEMINI_OCR_MODEL en .env (default: gemini-3.8-flash). Si el modelo esta
saturado (503) o retirado (404), reintenta y prueba un fallback.

La clave viaja en el header `x-goog-api-key` (nunca en la URL), para que no
aparezca en los mensajes de error de `requests`.
"""
from __future__ import annotations

import base64
import hashlib
import json
import mimetypes
import time
from pathlib import Path

import requests

from . import env

_API = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
DEFAULT_MODEL = "gemini-3.8-flash"
FALLBACK_MODELS = ("gemini-3.7-flash", "gemini-3.6-flash")
MAX_ATTEMPTS = 2
RETRY_STATUS = {429, 500, 502, 503, 504}
CACHE_VERSION = 1

PROMPT = (
    "Transcribe TODO el texto de esta imagen (foto de examen, pizarra o material de clase). "
    "Reglas: manten el orden de lectura; transcribe formulas y operaciones tal cual (texto plano o LaTeX simple); "
    "si hay tablas, diagramas o graficos, describelos en una linea empezando con [FIGURA: ...]; "
    "marca lo que no se lea como [ilegible]. No inventes ni completes nada que no se vea en la imagen."
)


def _models(model: str | None) -> list[str]:
    primary = (model or env.get("GEMINI_OCR_MODEL", DEFAULT_MODEL)).strip()
    ordered = [primary]
    for candidate in FALLBACK_MODELS:
        if candidate not in ordered:
            ordered.append(candidate)
    return ordered


def _prompt_hash() -> str:
    return hashlib.sha256(f"v{CACHE_VERSION}|{PROMPT}".encode("utf-8")).hexdigest()[:12]


def _cache_paths(raw: bytes) -> tuple[Path, Path]:
    digest = hashlib.sha256(raw).hexdigest()[:16]
    base = env.repo_root() / ".cache" / "ocr"
    return base / f"{digest}.json", base / f"{digest}.txt"


def _cache_read(raw: bytes) -> str | None:
    json_path, legacy_path = _cache_paths(raw)
    if json_path.is_file():
        try:
            data = json.loads(json_path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return None
        text = data.get("text")
        if data.get("prompt_hash") == _prompt_hash() and isinstance(text, str) and text.strip():
            return text.strip()
        return None
    if legacy_path.is_file():
        text = legacy_path.read_text(encoding="utf-8").strip()
        return text or None
    return None


def _cache_write(raw: bytes, text: str, model: str) -> None:
    json_path, _ = _cache_paths(raw)
    json_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {"text": text, "model": model, "prompt_hash": _prompt_hash()}
    json_path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")


def ocr_image(path: Path, model: str | None = None) -> str:
    """Devuelve el texto transcrito de la imagen (con cache local por hash)."""
    raw = path.read_bytes()
    cached = _cache_read(raw)
    if cached:
        return cached

    key = env.get("GEMINI_API_KEY")
    if not key:
        raise RuntimeError("Falta GEMINI_API_KEY en el .env del repo")

    mime = mimetypes.guess_type(path.name)[0] or "image/jpeg"
    data = base64.b64encode(raw).decode("ascii")
    payload = {
        "contents": [
            {
                "parts": [
                    {"text": PROMPT},
                    {"inline_data": {"mime_type": mime, "data": data}},
                ]
            }
        ],
        "generationConfig": {"temperature": 0.0},
    }

    errors: list[str] = []
    for candidate in _models(model):
        for attempt in range(MAX_ATTEMPTS):
            try:
                response = requests.post(
                    _API.format(model=candidate),
                    json=payload,
                    headers={"x-goog-api-key": key},
                    timeout=90,
                )
            except requests.RequestException as exc:
                # No incluir exc: su texto puede contener la URL de la peticion.
                errors.append(f"{candidate}: {type(exc).__name__} (red/timeout)")
                time.sleep(2)
                break  # red/timeout -> probar el siguiente modelo (no repetir 90s)

            if response.status_code == 200:
                body = response.json()
                candidates = body.get("candidates") or []
                parts = (candidates[0].get("content") or {}).get("parts") if candidates else None
                text = "\n".join(part.get("text", "") for part in (parts or [])).strip()
                if text:
                    _cache_write(raw, text, candidate)
                    return text
                errors.append(f"{candidate}: respuesta sin texto util")
                break

            errors.append(f"{candidate} HTTP {response.status_code}: {response.text[:160]}")
            if response.status_code in RETRY_STATUS:
                time.sleep(2 * (attempt + 1))
                continue
            break  # 404 / 400 -> probar el siguiente modelo

    raise RuntimeError("Gemini OCR fallo: " + " | ".join(errors[-6:]))
