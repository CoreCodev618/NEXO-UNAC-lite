"""Carga de variables desde el .env del repo (formato CI4: KEY = valor)."""
from __future__ import annotations

import os
from functools import lru_cache
from pathlib import Path


def repo_root() -> Path:
    # ia/python/nexo/env.py -> ia/
    return Path(__file__).resolve().parents[2]


@lru_cache(maxsize=1)
def load_env() -> dict[str, str]:
    """Lee KEY = valor del .env del repo. No sobreescribe variables de entorno reales."""
    values: dict[str, str] = {}
    env_file = repo_root() / ".env"
    if env_file.is_file():
        for line in env_file.read_text(encoding="utf-8", errors="replace").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, value = line.partition("=")
            values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def get(key: str, default: str = "") -> str:
    """Variable de entorno real o del .env del repo."""
    return os.environ.get(key) or load_env().get(key, default)
