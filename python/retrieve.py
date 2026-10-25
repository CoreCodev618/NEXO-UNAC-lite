#!/usr/bin/env python3
"""IA-5: retrieval top-N desde Qdrant con filtros de curso/fecha/tema/doc.

Uso:
    python/.venv/bin/python python/retrieve.py "consulta" \
        [--top 5] [--curso-id 4] [--class-date 2026-10-19] [--topic "..."] \
        [--doc-id N] [--min-score 0.7] [--collection nexo_docs] \
        [--qdrant-url URL] [--json]

El embedding de la consulta se calcula **despues** de verificar que Qdrant
responde, para no gastar cuota si el contenedor esta apagado. El progreso de
`embeddings.py` va a stderr: `--json` solo escribe JSON en stdout.
"""
from __future__ import annotations

import argparse
import json
import signal
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from nexo import embeddings, vectorstore  # noqa: E402

PREVIEW_CHARS = 240


def _positive_int(value: str) -> int:
    try:
        number = int(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("debe ser un entero") from exc
    if number <= 0:
        raise argparse.ArgumentTypeError("debe ser > 0")
    return number


def _iso_date_arg(value: str) -> str:
    try:
        return vectorstore.normalize_class_date(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(str(exc)) from exc


def _preview(text: str, limit: int = PREVIEW_CHARS) -> str:
    flat = " ".join((text or "").split())
    return flat[:limit] + ("..." if len(flat) > limit else "")


def _meta(payload: dict) -> str:
    parts = []
    for key, label in (
        ("curso_id", "curso"),
        ("class_date", "fecha"),
        ("topic", "tema"),
        ("doc_id_str", "doc"),
        ("chunk_key", "chunk"),
    ):
        value = payload.get(key)
        if value not in (None, ""):
            parts.append(f"{label}={value}")
    return " · ".join(parts)


def main() -> int:
    parser = argparse.ArgumentParser(description="IA-5: retrieval filtrado desde Qdrant")
    parser.add_argument("query", help="consulta en lenguaje natural")
    parser.add_argument("--top", type=_positive_int, default=5)
    parser.add_argument("--curso-id", type=_positive_int, default=None)
    parser.add_argument("--class-date", type=_iso_date_arg, default=None)
    parser.add_argument("--topic", default=None)
    parser.add_argument("--doc-id", type=_positive_int, default=None)
    parser.add_argument(
        "--min-score",
        type=float,
        default=None,
        help="umbral de similitud (0 o negativo tambien se aplican)",
    )
    parser.add_argument("--collection", default=vectorstore.DEFAULT_COLLECTION)
    parser.add_argument("--qdrant-url", default=None, help="default: QDRANT_URL del .env")
    parser.add_argument("--json", action="store_true", help="salida JSON (score + payload)")
    args = parser.parse_args()

    query = args.query.strip()
    if not query:
        print("ERROR: la consulta no puede estar vacia", file=sys.stderr)
        return 2

    try:
        filters = vectorstore.build_filter(
            curso_id=args.curso_id,
            class_date=args.class_date,
            topic=args.topic,
            doc_id=args.doc_id,
        )
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    try:
        store = vectorstore.QdrantStore(args.qdrant_url, args.collection)
        store.collection_info()  # falla rapido si Qdrant esta caido (no gasta embedding)
        vector = embeddings.embed_query(query)
        hits = store.search(
            vector,
            limit=args.top,
            filters=filters,
            score_threshold=args.min_score,
        )
    except vectorstore.QdrantError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    except RuntimeError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    if not hits:
        if args.json:
            print("[]")
        print("Sin resultados para los filtros dados.", file=sys.stderr)
        return 0

    if args.json:
        output = [
            {
                "score": round(float(hit.get("score", 0.0)), 6),
                "payload": hit.get("payload") or {},
            }
            for hit in hits
        ]
        print(json.dumps(output, ensure_ascii=False, indent=2))
        return 0

    for rank, hit in enumerate(hits, start=1):
        payload = hit.get("payload") or {}
        meta = _meta(payload)
        print(
            f"{rank}. score={float(hit.get('score', 0.0)):.3f}"
            + (f"  ({meta})" if meta else "")
        )
        source = payload.get("source") or payload.get("file_path") or "?"
        location = payload.get("location") or "?"
        print(f"   {source} · {location}")
        print(f"   {_preview(payload.get('text', ''))}")
        print()
    return 0


if __name__ == "__main__":
    try:
        signal.signal(signal.SIGPIPE, signal.SIG_DFL)
    except (AttributeError, ValueError):
        pass
    raise SystemExit(main())
