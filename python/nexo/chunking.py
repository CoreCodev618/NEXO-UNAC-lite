"""Chunking para RAG (IA-4).

Divide el texto ya extraido en trozos de <= 500 tokens con 50 de solape,
cortando por oracion, y conserva la ubicacion (pagina / slide / archivo)
para poder citar la fuente despues.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

try:
    import tiktoken
except ImportError:  # pragma: no cover - requirements lo instala
    tiktoken = None

DEFAULT_CHUNK_TOKENS = 500
DEFAULT_OVERLAP_TOKENS = 50

_SENTENCE_RE = re.compile(r"(?<=[.!?;:])\s+|\n+")
_ENC = None
_ENC_FAILED = False


def _encoding():
    global _ENC, _ENC_FAILED
    if _ENC is None and tiktoken is not None and not _ENC_FAILED:
        try:
            _ENC = tiktoken.get_encoding("cl100k_base")
        except Exception:  # noqa: BLE001 - p. ej. sin red para bajar el BPE
            _ENC_FAILED = True  # se usa la estimacion ~4 chars/token
    return _ENC


def count_tokens(text: str) -> int:
    """Tokens reales (cl100k_base); sin tiktoken/encoding, aproxima ~4 chars/token."""
    enc = _encoding()
    if enc is None:
        return max(1, len(text) // 4)
    return len(enc.encode(text, disallowed_special=()))


@dataclass
class Chunk:
    text: str
    doc_id: int
    chunk_index: int
    source: str
    kind: str
    location: str
    tokens: int
    source_key: str = ""

    @property
    def chunk_id(self) -> str:
        return f"{self.doc_id}-{self.chunk_index}"


def _string_windows(text: str, max_chars: int, overlap_chars: int) -> list[str]:
    step = max(1, max_chars - overlap_chars)
    return [
        text[i : i + max_chars].strip()
        for i in range(0, len(text), step)
        if text[i : i + max_chars].strip()
    ]


def _token_windows(text: str, max_tokens: int, overlap_tokens: int) -> list[str]:
    enc = _encoding()
    if enc is None:
        return _string_windows(text, max_tokens * 4, overlap_tokens * 4)

    ids = enc.encode(text, disallowed_special=())
    total = len(ids)
    windows: list[str] = []
    start = 0

    while start < total:
        end = min(start + max_tokens, total)
        part = enc.decode(ids[start:end])
        cleaned = part.strip()
        # Limpiar el espacio inicial cambia la tokenizacion (+1 token); si
        # pasa, recortamos la ventana hasta volver a cumplir el maximo.
        while cleaned and count_tokens(cleaned) > max_tokens and end > start + 1:
            end -= 1
            cleaned = enc.decode(ids[start:end]).strip()
        if cleaned:
            windows.append(cleaned)
        if end >= total:
            break
        start = max(start + 1, end - overlap_tokens)  # guarda anti-bucle

    return windows


def _emit(chunks: list[str], text: str, max_tokens: int, overlap_tokens: int) -> None:
    """Agrega un chunk garantizando <= max_tokens (lo reparte si hace falta)."""
    text = text.strip()
    if not text:
        return
    if count_tokens(text) <= max_tokens:
        chunks.append(text)
        return
    chunks.extend(_token_windows(text, max_tokens, overlap_tokens))


def split_text(
    text: str,
    max_tokens: int = DEFAULT_CHUNK_TOKENS,
    overlap_tokens: int = DEFAULT_OVERLAP_TOKENS,
) -> list[str]:
    """Parte un texto en chunks de hasta `max_tokens` con solape, cortando por oracion."""
    text = (text or "").strip()
    if not text:
        return []

    max_tokens = max(50, int(max_tokens))
    overlap_tokens = max(0, min(int(overlap_tokens), max_tokens // 2))

    if count_tokens(text) <= max_tokens:
        return [text]

    enc = _encoding()
    if enc is None:
        return _token_windows(text, max_tokens, overlap_tokens)

    sentences = [s.strip() for s in _SENTENCE_RE.split(text) if s and s.strip()]
    pieces = [(s, enc.encode(s, disallowed_special=())) for s in sentences]

    chunks: list[str] = []
    current: list[tuple[str, list[int]]] = []
    current_tokens = 0

    def flush() -> None:
        nonlocal current, current_tokens
        if current:
            _emit(chunks, " ".join(s for s, _ in current), max_tokens, overlap_tokens)
            current = []
            current_tokens = 0

    for sentence, tokens in pieces:
        if len(tokens) > max_tokens:
            flush()
            chunks.extend(_token_windows(sentence, max_tokens, overlap_tokens))
            continue

        if current and current_tokens + len(tokens) > max_tokens:
            _emit(chunks, " ".join(s for s, _ in current), max_tokens, overlap_tokens)
            # solape: conservar las ultimas oraciones que quepan en overlap_tokens
            keep: list[tuple[str, list[int]]] = []
            keep_tokens = 0
            for piece in reversed(current):
                if keep_tokens + len(piece[1]) <= overlap_tokens:
                    keep.insert(0, piece)
                    keep_tokens += len(piece[1])
                else:
                    break
            current = keep
            current_tokens = keep_tokens

        current.append((sentence, tokens))
        current_tokens += len(tokens)

    flush()
    return [chunk for chunk in chunks if chunk]


def chunk_extraction(
    items,
    *,
    max_tokens: int = DEFAULT_CHUNK_TOKENS,
    overlap_tokens: int = DEFAULT_OVERLAP_TOKENS,
) -> list[Chunk]:
    """Convierte `extractors.Extracted` (con `doc_id` ya asignado) en chunks."""
    chunks: list[Chunk] = []

    for item in items:
        doc_id = int(item.doc_id)
        source_key = getattr(item, "source_key", "") or item.source
        chunk_index = 0

        for unit in item.units:
            for part in split_text(unit.text, max_tokens=max_tokens, overlap_tokens=overlap_tokens):
                chunks.append(
                    Chunk(
                        text=part,
                        doc_id=doc_id,
                        chunk_index=chunk_index,
                        source=item.source,
                        kind=item.kind,
                        location=unit.location,
                        tokens=count_tokens(part),
                        source_key=source_key,
                    )
                )
                chunk_index += 1

    return chunks
