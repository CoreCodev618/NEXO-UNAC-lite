#!/usr/bin/env python3
"""IA-5: ingest de un documento a Qdrant, idempotente por UUID5.

Uso:
    python/.venv/bin/python python/ingest.py TARGET --doc-id N \
        --curso-id 2 [--class-date 2026-10-20] [--topic "Programación Web II"] \
        [--collection nexo_docs] [--qdrant-url URL] [--batch 64]

Identidad:
- `--doc-id` es el `documents.id` real de MySQL (obligatorio).
- Si TARGET (zip/carpeta) produce varias fuentes, se fusionan en un unico
  documento: `chunk_index` es correlativo y las ubicaciones llevan la fuente
  interna ("curso.zip :: a.pdf · pagina 3").

Reingestar el mismo material no duplica puntos: el id de cada punto es
`uuid5(NAMESPACE_URL, "nexo:{doc_id}:{chunk_index}")`. Tras el upsert se
podan siempre los puntos huerfanos del mismo `doc_id`.
"""
from __future__ import annotations

import argparse
import signal
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from nexo import chunking, embeddings, env, extractors, vectorstore  # noqa: E402

# -- argumentos -------------------------------------------------------------


def _positive_int(value: str) -> int:
    try:
        number = int(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("debe ser un entero") from exc
    if number <= 0:
        raise argparse.ArgumentTypeError("debe ser > 0")
    return number


def _non_negative_int(value: str) -> int:
    try:
        number = int(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("debe ser un entero") from exc
    if number < 0:
        raise argparse.ArgumentTypeError("debe ser >= 0")
    return number


def _chunk_tokens(value: str) -> int:
    number = _positive_int(value)
    if number < 50:
        raise argparse.ArgumentTypeError("debe ser >= 50")
    return number


def _iso_date_arg(value: str) -> str:
    try:
        return vectorstore.normalize_class_date(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(str(exc)) from exc


# -- helpers ----------------------------------------------------------------


def _error(message: str) -> int:
    print(f"ERROR: {message}", file=sys.stderr)
    return 1


def _warn_all(warnings: list[str]) -> None:
    for warning in warnings:
        print(f"AVISO: {warning}", file=sys.stderr)


def _preview(text: str, limit: int = 140) -> str:
    flat = " ".join(text.split())
    return flat[:limit] + ("..." if len(flat) > limit else "")


def _canonical_source(target: Path, label: str) -> str:
    """Identidad estable y portatil: ruta relativa al proyecto cuando se puede."""
    project_root = env.repo_root().resolve()
    try:
        base = target.resolve().relative_to(project_root).as_posix()
    except ValueError:
        base = target.resolve().as_posix()

    if target.is_dir():
        prefix = f"{target.name}/"
        if label.startswith(prefix):
            return f"{base}/{label[len(prefix):]}"
    else:
        if label == target.name:
            return base
        prefix = f"{target.name} :: "
        if label.startswith(prefix):
            return f"{base} :: {label[len(prefix):]}"
    return f"{base}::{label}"


def _merge(items: list[extractors.Extracted], source: str) -> extractors.Extracted:
    """Fusiona varias fuentes (zip/carpeta) en un documento con chunk_index correlativo."""
    if len(items) == 1:
        return items[0]
    kinds = {item.kind for item in items}
    return extractors.Extracted(
        source=source,
        kind=kinds.pop() if len(kinds) == 1 else "mixto",
        units=[
            extractors.Unit(f"{item.source} · {unit.location}", unit.text)
            for item in items
            for unit in item.units
        ],
    )


def _points(
    chunks: list[chunking.Chunk], vectors: list[list[float]], args
) -> list[dict]:
    return [
        {
            "id": vectorstore.point_id(chunk.doc_id, chunk.chunk_index),
            "vector": vector,
            "payload": vectorstore.payload_for_chunk(
                chunk, args.curso_id, args.class_date, args.topic
            ),
        }
        for chunk, vector in zip(chunks, vectors)
    ]


def _source_mismatches(existing: list[dict], source_key: str, source: str) -> list[str]:
    """Detecta un doc_id ya ingestado desde otra fuente (evita sobreescrituras)."""
    # Puntos viejos guardaban source_key relativo al padre del repo.
    legacy_key = f"{env.repo_root().name}/{source_key}"
    problems: set[str] = set()
    for point in existing:
        payload = point.get("payload") or {}
        old_key = payload.get("source_key")
        old_label = payload.get("source")
        if old_key:
            if old_key not in (source_key, legacy_key):
                problems.add(f"existente={old_key} nuevo={source_key}")
        elif old_label and old_label != source:
            # Puntos legacy sin source_key: mejor esfuerzo por etiqueta.
            problems.add(f"existente={old_label} nuevo={source}")
    return sorted(problems)


# -- main -------------------------------------------------------------------


def main() -> int:
    parser = argparse.ArgumentParser(description="IA-5: ingest de chunks a Qdrant")
    parser.add_argument("target", help="archivo, carpeta o .zip")
    parser.add_argument(
        "--doc-id",
        type=_positive_int,
        required=True,
        help="documents.id de MySQL",
    )
    parser.add_argument("--curso-id", type=_positive_int, default=1)
    parser.add_argument(
        "--class-date",
        type=_iso_date_arg,
        default=None,
        help="fecha de clase en formato YYYY-MM-DD",
    )
    parser.add_argument("--topic", default="")
    parser.add_argument(
        "--max-tokens", type=_chunk_tokens, default=chunking.DEFAULT_CHUNK_TOKENS
    )
    parser.add_argument(
        "--overlap", type=_non_negative_int, default=chunking.DEFAULT_OVERLAP_TOKENS
    )
    parser.add_argument("--collection", default=vectorstore.DEFAULT_COLLECTION)
    parser.add_argument("--qdrant-url", default=None, help="default: QDRANT_URL del .env")
    parser.add_argument(
        "--batch", type=_positive_int, default=vectorstore.UPSERT_BATCH
    )
    args = parser.parse_args()

    if args.overlap > args.max_tokens // 2:
        parser.error("--overlap no puede superar --max-tokens / 2")

    args.class_date = args.class_date or ""
    args.topic = args.topic.strip()
    store = vectorstore.QdrantStore(args.qdrant_url, args.collection)

    path = Path(args.target).expanduser()
    if not path.exists():
        return _error(f"no existe: {path.resolve()}")
    path = path.resolve()

    try:
        extraction = extractors.extract_path(path)
    except Exception as exc:  # noqa: BLE001
        return _error(f"extrayendo {path}: {type(exc).__name__}: {exc}")
    warnings = extraction.warnings

    if not extraction.items:
        _warn_all(warnings)
        return _error(f"no se extrajo texto de {path.name}")

    item = _merge(extraction.items, path.name)
    item.source_key = _canonical_source(path, item.source)
    item.doc_id = args.doc_id

    chunks = chunking.chunk_extraction(
        [item], max_tokens=args.max_tokens, overlap_tokens=args.overlap
    )

    print("=" * 70)
    print(f"{path.name}: {len(extraction.items)} fuentes -> {len(chunks)} chunks")

    if not chunks:
        _warn_all(warnings)
        return _error("no se generaron chunks")

    tokens = [chunk.tokens for chunk in chunks]
    kinds = Counter(chunk.kind for chunk in chunks)
    print("=" * 70)
    print(
        f"Total: {len(chunks)} chunks únicos · "
        f"tokens min/avg/max = {min(tokens)}/{sum(tokens) // len(tokens)}/{max(tokens)}"
    )
    print("Por tipo: " + ", ".join(f"{n} {k}" for k, n in sorted(kinds.items())))
    print(f"\nDocumento: doc_id={args.doc_id} -> {item.source_key}")

    sample = chunks[0]
    print(f"\nMuestra [{sample.chunk_id}] {sample.source} · {sample.location}")
    print(f"  > {_preview(sample.text)}")

    try:
        store.ensure_collection(embeddings.dimension())
        store.ensure_payload_indexes()
        existing = store.scroll(
            filters=vectorstore.build_filter(doc_id=args.doc_id),
            with_payload=["source", "source_key"],
        )
    except vectorstore.QdrantError as exc:
        return _error(str(exc))

    problems = _source_mismatches(existing, item.source_key, item.source)
    if problems:
        for problem in problems[:10]:
            print(f"  doc_id={args.doc_id}: {problem}", file=sys.stderr)
        return _error("doc_id ya existe en Qdrant con otra fuente.")

    print(
        f"\nEmbeddings con {embeddings.model_name()} "
        f"(dim {embeddings.dimension()}) para {len(chunks)} chunks..."
    )
    try:
        vectors, stats = embeddings.embed_texts(
            [chunk.text for chunk in chunks], return_stats=True
        )
    except RuntimeError as exc:
        return _error(str(exc))

    if len(vectors) != len(chunks):
        return _error(
            f"embeddings devolvió {len(vectors)} vectores para {len(chunks)} chunks"
        )
    print(
        f"Embeddings: {len(vectors)} chunks · únicos: {stats.unique} · "
        f"en caché: {stats.cached} · nuevos: {stats.new}"
    )

    try:
        points = _points(chunks, vectors, args)
        sent = store.upsert(points, wait=True, batch_size=args.batch)
        current = {point["id"] for point in points}
        orphans = sorted(
            {str(p["id"]) for p in existing if p.get("id")} - current
        )
        if orphans:
            store.delete_points(orphans)
    except vectorstore.QdrantError as exc:
        return _error(str(exc))
    except (KeyError, TypeError, ValueError) as exc:
        return _error(f"preparando puntos: {type(exc).__name__}: {exc}")

    print(f"Puntos enviados: {sent} (lotes de {args.batch})")
    print(f"Poda: {len(orphans)} punto(s) huérfano(s) eliminados.")

    _warn_all(warnings)
    return 0


if __name__ == "__main__":
    try:
        signal.signal(signal.SIGPIPE, signal.SIG_DFL)
    except (AttributeError, ValueError):
        pass
    raise SystemExit(main())
