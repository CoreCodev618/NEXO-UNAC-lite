#!/usr/bin/env python3
"""IA-3: extraccion de material de clases (docx / pptx / pdf / fotos / codigo / zip).

Uso:
    python/extract.py <archivo|carpeta|zip> [--preview 220] [--out CARPETA]

Ejemplos:
    python/.venv/bin/python python/extract.py "../samples"
    python/.venv/bin/python python/extract.py "../samples/SILABUS  Programacion Web II 2026B.docx"
    python/.venv/bin/python python/extract.py "../samples/SEMANA 6.zip" --out /tmp/opencode/ia3-out
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from nexo import extractors  # noqa: E402


def _preview(text: str, limit: int) -> str:
    flat = re.sub(r"\s+", " ", text).strip()
    return flat[:limit] + ("..." if len(flat) > limit else "")


def _save(out_dir: Path, item: extractors.Extracted) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)
    safe = re.sub(r"[^A-Za-z0-9._-]+", "_", item.source).strip("_")[:120] or "fuente"
    lines = [f"# fuente: {item.source} ({item.kind})", f"# unidades: {len(item.units)}", ""]
    for unit in item.units:
        lines.append(f"----- {unit.location} -----")
        lines.append(unit.text)
        lines.append("")
    (out_dir / f"{safe}.txt").write_text("\n".join(lines), encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Extraccion multi-formato para RAG (IA-3)")
    parser.add_argument("target", help="archivo, carpeta o .zip")
    parser.add_argument("--preview", type=int, default=220, help="chars de vista previa (0 = ninguna)")
    parser.add_argument("--out", type=Path, default=None, help="carpeta donde guardar el texto extraido")
    args = parser.parse_args()

    target = Path(args.target).expanduser().resolve()
    if not target.exists():
        print(f"ERROR: no existe: {target}")
        return 2

    res = extractors.extract_path(target)

    print(f"Objetivo: {target}")
    print("=" * 70)
    total_chars = 0
    kinds: dict[str, int] = {}
    for item in res.items:
        total_chars += item.chars
        kinds[item.kind] = kinds.get(item.kind, 0) + 1
        print(f"[{item.kind}] {item.source}")
        print(f"   unidades: {len(item.units)} · chars: {item.chars:,}")
        if args.preview and item.units:
            first = next((u for u in item.units if u.text.strip()), item.units[0])
            print(f"   > {_preview(first.text, args.preview)}")
        if args.out:
            _save(args.out, item)
        print()

    print("=" * 70)
    summary = ", ".join(f"{n} {k}" for k, n in sorted(kinds.items())) or "nada"
    print(f"Total: {len(res.items)} fuentes ({summary}) · {total_chars:,} chars")
    if args.out:
        print(f"Texto guardado en: {args.out}")
    if res.warnings:
        print()
        for warning in res.warnings:
            print(f"AVISO: {warning}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
