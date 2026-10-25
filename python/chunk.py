#!/usr/bin/env python3
"""IA-4: chunking (~500 tokens) + embeddings del material de clases.

Uso:
    python/chunk.py <archivo|carpeta|zip> [...] [--embed] [--query "..."] [--out CARPETA]

Ejemplos:
    python/.venv/bin/python python/chunk.py "../samples" --curso-id 1
    python/.venv/bin/python python/chunk.py "../samples/Semana 7 Programación entera.pptx" \
        --embed --query "¿Qué es programación entera?"
"""
from __future__ import annotations

import argparse
import json
import math
import re
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from nexo import chunking, embeddings, extractors  # noqa: E402


def _preview(text: str, limit: int = 170) -> str:
    flat = re.sub(r"\s+", " ", text).strip()
    return flat[:limit] + ("..." if len(flat) > limit else "")


def _cosine(a: list[float], b: list[float]) -> float:
    if len(a) != len(b):
        raise ValueError(f"Vectores de distinta dimension: {len(a)} != {len(b)}")
    dot = sum(x * y for x, y in zip(a, b))
    norm_a = math.sqrt(sum(x * x for x in a))
    norm_b = math.sqrt(sum(y * y for y in b))
    return dot / (norm_a * norm_b) if norm_a and norm_b else 0.0


def _record(chunk: chunking.Chunk, vector: list[float] | None = None) -> dict:
    record = {
        "chunk_id": chunk.chunk_id,
        "doc_id": chunk.doc_id,
        "chunk_index": chunk.chunk_index,
        "text": chunk.text,
        "tokens": chunk.tokens,
        "metadata": chunk.metadata,
    }
    if vector is not None:
        record["vector"] = vector
    return record


def main() -> int:
    parser = argparse.ArgumentParser(description="IA-4: chunking + embeddings para RAG")
    parser.add_argument("targets", nargs="+", help="archivos, carpetas o .zip")
    parser.add_argument(
        "--doc-id",
        type=int,
        default=None,
        help="doc_id inicial secuencial (default: hash estable de la ruta)",
    )
    parser.add_argument("--curso-id", type=int, default=1)
    parser.add_argument("--class-date", default="")
    parser.add_argument("--topic", default="")
    parser.add_argument("--max-tokens", type=int, default=chunking.DEFAULT_CHUNK_TOKENS)
    parser.add_argument("--overlap", type=int, default=chunking.DEFAULT_OVERLAP_TOKENS)
    parser.add_argument("--embed", action="store_true", help="calcula embeddings (Gemini)")
    parser.add_argument("--query", default=None, help="prueba de retrieval: top chunks por similitud")
    parser.add_argument("--top", type=int, default=3)
    parser.add_argument("--out", type=Path, default=None, help="carpeta para guardar JSONL")
    args = parser.parse_args()

    if args.query and not args.embed:
        print("AVISO: --query requiere --embed; se ignora la consulta.")

    all_chunks: list[chunking.Chunk] = []
    warnings: list[str] = []
    next_doc_id = args.doc_id or 0
    sources_by_target: list[tuple[str, int, int]] = []

    for target in args.targets:
        path = Path(target).expanduser().resolve()
        if not path.exists():
            print(f"ERROR: no existe: {path}")
            continue

        try:
            extraction = extractors.extract_path(path)
        except Exception as exc:  # noqa: BLE001
            print(f"ERROR extrayendo {path}: {type(exc).__name__}: {exc}")
            continue

        warnings.extend(extraction.warnings)
        chunks = chunking.chunk_extraction(
            extraction.items,
            doc_id_start=next_doc_id,
            stable_ids=args.doc_id is None,
            curso_id=args.curso_id,
            class_date=args.class_date,
            topic=args.topic,
            max_tokens=args.max_tokens,
            overlap_tokens=args.overlap,
        )
        next_doc_id += len(extraction.items)
        all_chunks.extend(chunks)
        sources_by_target.append((path.name, len(extraction.items), len(chunks)))

    print("=" * 70)
    for name, n_sources, n_chunks in sources_by_target:
        print(f"{name}: {n_sources} fuentes -> {n_chunks} chunks")

    if not all_chunks:
        print("No se generaron chunks.")
        for warning in warnings:
            print(f"AVISO: {warning}")
        return 1

    token_counts = [c.tokens for c in all_chunks]
    kinds = Counter(c.kind for c in all_chunks)
    print("=" * 70)
    print(
        f"Total: {len(all_chunks)} chunks · tokens min/avg/max = "
        f"{min(token_counts)}/{sum(token_counts) // len(token_counts)}/{max(token_counts)}"
    )
    print("Por tipo: " + ", ".join(f"{n} {k}" for k, n in sorted(kinds.items())))

    sample = all_chunks[0]
    print(f"\nMuestra [{sample.chunk_id}] {sample.source} · {sample.location} · {sample.tokens} tokens")
    print(f"  > {_preview(sample.text)}")

    vectors: list[list[float]] | None = None
    if args.embed:
        print(
            f"\nEmbeddings con {embeddings.model_name()} (dim {embeddings.dimension()}) "
            f"para {len(all_chunks)} chunks..."
        )
        vectors = embeddings.embed_texts([c.text for c in all_chunks])
        dims = {len(v) for v in vectors}
        print(f"Vectores: {len(vectors)} · dimensiones: {sorted(dims)}")
        if dims != {embeddings.dimension()}:
            print("ERROR: dimension inesperada")
            return 1

    if vectors and args.query:
        query_vector = embeddings.embed_query(args.query)
        scored = sorted(
            ((_cosine(query_vector, vector), chunk) for chunk, vector in zip(all_chunks, vectors)),
            key=lambda pair: pair[0],
            reverse=True,
        )
        print(f'\nRetrieval de prueba: "{args.query}" (top {args.top})')
        for score, chunk in scored[: args.top]:
            print(f"  {score:.3f}  [{chunk.chunk_id}] {chunk.source} · {chunk.location}")
            print(f"         {_preview(chunk.text, 150)}")

    if args.out:
        args.out.mkdir(parents=True, exist_ok=True)
        output = args.out / "chunks.jsonl"
        with output.open("w", encoding="utf-8") as handle:
            for index, chunk in enumerate(all_chunks):
                record = _record(chunk, vectors[index] if vectors else None)
                handle.write(json.dumps(record, ensure_ascii=False) + "\n")
        print(f"\nJSONL guardado en: {output}")

    if warnings:
        print()
        for warning in warnings:
            print(f"AVISO: {warning}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
