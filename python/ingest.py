#!/usr/bin/env python3
"""IA-5: ingest del material chunked a Qdrant, idempotente por UUID5.

Uso:
    python/.venv/bin/python python/ingest.py TARGETS... \
        --curso-id 2 --class-date 2026-10-20 --topic "Programación Web II" \
        [--doc-id N | --doc-id-map ids.json] [--collection nexo_docs] \
        [--qdrant-url URL] [--batch 64] [--no-prune] [--dry-run]

Identidad:
- Sin `--doc-id`/`--doc-id-map`, el `doc_id` es un hash estable de la etiqueta
  de fuente (modo demo). Si dos fuentes distintas colisionan, el ingest aborta.
- `--doc-id N` solo vale si hay exactamente una fuente.
- `--doc-id-map ids.json` asigna el `documents.id` real de MySQL por fuente
  canonica: `{"samples/curso/archivo.pdf": 123}` o una lista de
  `{"source": "...", "doc_id": 123}`.
- Siempre se imprime el mapeo `doc_id -> fuente` para poder auditarlo.

Reingestar el mismo material no duplica puntos: el id de cada punto es
`uuid5(NAMESPACE_URL, "nexo:{doc_id}:{chunk_index}")`. Ademas, tras el upsert
se podan los puntos huerfanos del mismo `doc_id` (salvo `--no-prune`).
"""
from __future__ import annotations

import argparse
import json
import signal
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from nexo import chunking, embeddings, env, extractors, vectorstore  # noqa: E402

SCROLL_DOC_BATCH = 256


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
    project_root = env.repo_root().parent.resolve()
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


def _load_doc_id_map(path: Path) -> dict[str, int]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except OSError as exc:
        raise ValueError(f"no se pudo leer {path}: {exc}") from exc
    except ValueError as exc:
        raise ValueError(f"{path} no es JSON valido: {exc}") from exc

    if isinstance(data, dict):
        entries = list(data.items())
    elif isinstance(data, list):
        entries = []
        for row in data:
            if not isinstance(row, dict) or "source" not in row or "doc_id" not in row:
                raise ValueError(
                    "cada entrada de la lista debe tener 'source' y 'doc_id'"
                )
            entries.append((row["source"], row["doc_id"]))
    else:
        raise ValueError("el manifiesto debe ser un objeto {source: doc_id} o una lista")

    mapping: dict[str, int] = {}
    for source, doc_id in entries:
        if not isinstance(source, str) or not source.strip():
            raise ValueError("cada 'source' debe ser un string no vacio")
        if isinstance(doc_id, bool) or not isinstance(doc_id, int) or doc_id <= 0:
            raise ValueError(f"doc_id invalido para '{source}': usa un entero > 0")
        mapping[source.strip()] = doc_id
    if not mapping:
        raise ValueError("el manifiesto esta vacio")
    return mapping


def _source_conflicts(chunks: list[chunking.Chunk]) -> dict[int, list[str]]:
    """doc_id -> fuentes canonicas distintas (una colision es un error)."""
    by_doc: dict[int, set[str]] = {}
    for chunk in chunks:
        source = getattr(chunk, "source_key", "") or chunk.source
        by_doc.setdefault(chunk.doc_id, set()).add(source)
    return {
        doc_id: sorted(sources)
        for doc_id, sources in by_doc.items()
        if len(sources) > 1
    }


def _dedupe(chunks: list[chunking.Chunk]) -> tuple[list[chunking.Chunk], int]:
    """Dedupe por (doc_id, chunk_index), conservando el primer orden de aparicion."""
    seen: set[tuple[int, int]] = set()
    unique: list[chunking.Chunk] = []
    for chunk in chunks:
        key = (chunk.doc_id, chunk.chunk_index)
        if key in seen:
            continue
        seen.add(key)
        unique.append(chunk)
    return unique, len(chunks) - len(unique)


def _orphan_ids(existing_ids, current_ids) -> list[str]:
    """IDs de Qdrant que ya no corresponden al chunking actual del documento."""
    return sorted(set(existing_ids) - set(current_ids))


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


def _expected_sources(chunks: list[chunking.Chunk]) -> dict[int, tuple[str, str]]:
    result: dict[int, tuple[str, str]] = {}
    for chunk in chunks:
        source_key = getattr(chunk, "source_key", "") or chunk.source
        result.setdefault(chunk.doc_id, (source_key, chunk.source))
    return result


def _scroll_existing(store: vectorstore.QdrantStore, doc_ids) -> list[dict]:
    points: list[dict] = []
    ids = sorted({int(doc_id) for doc_id in doc_ids})
    for start in range(0, len(ids), SCROLL_DOC_BATCH):
        filters = {
            "must": [
                {"key": "doc_id", "match": {"any": ids[start : start + SCROLL_DOC_BATCH]}}
            ]
        }
        points.extend(
            store.scroll(
                filters=filters,
                with_payload=["doc_id", "source", "source_key"],
            )
        )
    return points


def _check_existing_sources(
    existing: list[dict], expected: dict[int, tuple[str, str]]
) -> list[str]:
    """Detecta un doc_id ya ingestado desde otra fuente (evita sobreescrituras)."""
    problems: list[str] = []
    for point in existing:
        payload = point.get("payload") or {}
        try:
            doc_id = int(payload.get("doc_id"))
        except (TypeError, ValueError):
            continue
        if doc_id not in expected:
            continue
        expected_key, expected_label = expected[doc_id]
        old_key = payload.get("source_key")
        old_label = payload.get("source")
        if old_key:
            if old_key != expected_key:
                problems.append(
                    f"doc_id={doc_id}: existente={old_key} nuevo={expected_key}"
                )
        elif old_label and old_label != expected_label:
            # Puntos legacy sin source_key: mejor esfuerzo por etiqueta.
            problems.append(
                f"doc_id={doc_id}: existente={old_label} nuevo={expected_label}"
            )
    return problems


def _existing_ids_by_doc(existing: list[dict]) -> dict[int, set[str]]:
    result: dict[int, set[str]] = {}
    for point in existing:
        payload = point.get("payload") or {}
        try:
            doc_id = int(payload.get("doc_id"))
        except (TypeError, ValueError):
            continue
        point_id_value = point.get("id")
        if point_id_value:
            result.setdefault(doc_id, set()).add(str(point_id_value))
    return result


# -- main -------------------------------------------------------------------


def main() -> int:
    parser = argparse.ArgumentParser(description="IA-5: ingest de chunks a Qdrant")
    parser.add_argument("targets", nargs="+", help="archivos, carpetas o .zip")
    doc_id_group = parser.add_mutually_exclusive_group()
    doc_id_group.add_argument(
        "--doc-id",
        type=_positive_int,
        default=None,
        help="documents.id de MySQL; solo si hay exactamente una fuente",
    )
    doc_id_group.add_argument(
        "--doc-id-map",
        type=Path,
        default=None,
        help="JSON {fuente: doc_id} para asignar IDs de MySQL por fuente",
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
    parser.add_argument(
        "--no-prune",
        dest="prune",
        action="store_false",
        help="no borrar puntos huerfanos del mismo doc_id tras el upsert",
    )
    parser.set_defaults(prune=True)
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="solo extrae/chunkea e imprime; no calcula embeddings ni escribe",
    )
    args = parser.parse_args()

    if args.overlap > args.max_tokens // 2:
        parser.error("--overlap no puede superar --max-tokens / 2")

    args.class_date = args.class_date or ""
    args.topic = args.topic.strip()
    store = vectorstore.QdrantStore(args.qdrant_url, args.collection)

    doc_id_map: dict[str, int] | None = None
    if args.doc_id_map is not None:
        try:
            doc_id_map = _load_doc_id_map(args.doc_id_map.expanduser())
        except ValueError as exc:
            return _error(str(exc))

    extractions: list[tuple[Path, extractors.Extraction]] = []
    items: list[extractors.Extracted] = []
    warnings: list[str] = []

    for target in args.targets:
        path = Path(target).expanduser()
        if not path.exists():
            return _error(f"no existe: {path.resolve()}")
        path = path.resolve()

        try:
            extraction = extractors.extract_path(path)
        except Exception as exc:  # noqa: BLE001
            return _error(f"extrayendo {path}: {type(exc).__name__}: {exc}")

        for item in extraction.items:
            item.source_key = _canonical_source(path, item.source)
        extractions.append((path, extraction))
        items.extend(extraction.items)
        warnings.extend(extraction.warnings)

    if not items:
        _warn_all(warnings)
        return _error(f"no se extrajo texto de {len(args.targets)} target(s)")

    # Asignacion de doc_id: manifiesto, unico explicito o hash estable.
    if doc_id_map is not None:
        missing: list[str] = []
        for item in items:
            key = item.source_key or item.source
            doc_id = doc_id_map.get(key)
            if doc_id is None:
                doc_id = doc_id_map.get(item.source)
            if doc_id is None:
                missing.append(key)
            else:
                item.doc_id = doc_id
        if missing:
            _warn_all(warnings)
            return _error(
                "faltan doc_id en el manifiesto para: " + ", ".join(sorted(missing)[:10])
            )
        used = {item.source_key for item in items} | {item.source for item in items}
        unused = sorted(set(doc_id_map) - used)
        if unused:
            print(
                f"AVISO: el manifiesto tiene {len(unused)} clave(s) sin uso: "
                + ", ".join(unused[:5]),
                file=sys.stderr,
            )
    elif args.doc_id is not None:
        if len(items) != 1:
            _warn_all(warnings)
            return _error(
                f"--doc-id requiere exactamente 1 fuente y hay {len(items)}; "
                "usa --doc-id-map"
            )
        items[0].doc_id = args.doc_id

    explicit_ids = doc_id_map is not None or args.doc_id is not None
    all_chunks: list[chunking.Chunk] = []
    sources: list[tuple[str, int, int]] = []

    for path, extraction in extractions:
        chunks = chunking.chunk_extraction(
            extraction.items,
            stable_ids=not explicit_ids,
            curso_id=args.curso_id,
            class_date=args.class_date,
            topic=args.topic,
            max_tokens=args.max_tokens,
            overlap_tokens=args.overlap,
        )
        all_chunks.extend(chunks)
        sources.append((path.name, len(extraction.items), len(chunks)))

    print("=" * 70)
    for name, n_sources, n_chunks in sources:
        print(f"{name}: {n_sources} fuentes -> {n_chunks} chunks")

    if not all_chunks:
        _warn_all(warnings)
        return _error("no se generaron chunks")

    conflicts = _source_conflicts(all_chunks)
    if conflicts:
        _warn_all(warnings)
        print(
            "ERROR: colision de doc_id entre fuentes distintas:",
            file=sys.stderr,
        )
        for doc_id, source_list in sorted(conflicts.items()):
            print(f"  doc_id={doc_id}: {', '.join(source_list)}", file=sys.stderr)
        print(
            "  usa --doc-id-map con los documents.id de MySQL (o separa las fuentes).",
            file=sys.stderr,
        )
        return 1

    chunks, duplicates = _dedupe(all_chunks)
    tokens = [chunk.tokens for chunk in chunks]
    kinds = Counter(chunk.kind for chunk in chunks)
    print("=" * 70)
    print(
        f"Total: {len(chunks)} chunks únicos · {duplicates} duplicados omitidos · "
        f"tokens min/avg/max = {min(tokens)}/{sum(tokens) // len(tokens)}/{max(tokens)}"
    )
    print("Por tipo: " + ", ".join(f"{n} {k}" for k, n in sorted(kinds.items())))

    doc_sources: dict[int, str] = {}
    for chunk in chunks:
        doc_sources.setdefault(chunk.doc_id, getattr(chunk, "source_key", "") or chunk.source)
    print("\nDocumentos (doc_id -> fuente):")
    for doc_id in sorted(doc_sources):
        print(f"  doc_id={doc_id} -> {doc_sources[doc_id]}")

    sample = chunks[0]
    print(f"\nMuestra [{sample.chunk_id}] {sample.source} · {sample.location}")
    print(f"  > {_preview(sample.text)}")

    if args.dry_run:
        print(
            f"\nDRY-RUN: {len(chunks)} chunks listos para '{args.collection}'; "
            "no se calcularon embeddings ni se escribió en Qdrant."
        )
        _warn_all(warnings)
        return 0

    try:
        store.ensure_collection(embeddings.dimension())
        store.ensure_payload_indexes()
        existing = _scroll_existing(store, doc_sources.keys())
        problems = _check_existing_sources(existing, _expected_sources(chunks))
    except vectorstore.QdrantError as exc:
        return _error(str(exc))

    if problems:
        for problem in problems[:10]:
            print(f"  {problem}", file=sys.stderr)
        return _error(
            "doc_id ya existe en Qdrant con otra fuente; usa --doc-id-map "
            "con IDs únicos."
        )

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
        pruned = 0
        if args.prune:
            current_by_doc: dict[int, set[str]] = {}
            for chunk in chunks:
                current_by_doc.setdefault(chunk.doc_id, set()).add(
                    vectorstore.point_id(chunk.doc_id, chunk.chunk_index)
                )
            orphans: list[str] = []
            for doc_id, existing_ids in _existing_ids_by_doc(existing).items():
                if doc_id in current_by_doc:
                    orphans.extend(_orphan_ids(existing_ids, current_by_doc[doc_id]))
            if orphans:
                store.delete_points(orphans)
                pruned = len(orphans)
        total = store.count()
    except vectorstore.QdrantError as exc:
        return _error(str(exc))
    except (KeyError, TypeError, ValueError) as exc:
        return _error(f"preparando puntos: {type(exc).__name__}: {exc}")

    print(f"Puntos enviados: {sent} (lotes de {args.batch})")
    if args.prune:
        print(f"Poda: {pruned} punto(s) huérfano(s) eliminados.")
    else:
        print("Poda: deshabilitada (--no-prune).")
    print(f"count() en '{args.collection}': {total}")

    _warn_all(warnings)
    return 0


if __name__ == "__main__":
    try:
        signal.signal(signal.SIGPIPE, signal.SIG_DFL)
    except (AttributeError, ValueError):
        pass
    raise SystemExit(main())
