"""Borra de Qdrant todos los fragmentos de un documento: python delete.py <doc_id>."""
from __future__ import annotations

import sys

from nexo import vectorstore


def main() -> int:
    if len(sys.argv) != 2 or not sys.argv[1].isdigit():
        print("ERROR: uso: delete.py <doc_id>", file=sys.stderr)
        return 2
    store = vectorstore.QdrantStore()
    store._request(
        "POST",
        f"/collections/{store.collection}/points/delete",
        {"filter": vectorstore.build_filter(doc_id=int(sys.argv[1]))},
        {"wait": "true"},
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
