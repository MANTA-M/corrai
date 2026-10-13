"""Dispatch OCR to PaddleOCR or python-doctr."""

from __future__ import annotations

from pathlib import Path
from typing import Any, Callable

from pycorrai.ocr import doctr_backend, paddle_backend

DEFAULT_ENGINE = "paddle"
ENGINES = frozenset({"paddle", "doctr"})

_BackendRecognize = Callable[[Path, str], list[dict[str, Any]]]
_BackendRecognizeBytes = Callable[[bytes, str], list[dict[str, Any]]]

_BACKENDS: dict[str, tuple[_BackendRecognize, _BackendRecognizeBytes]] = {
    "paddle": (paddle_backend.recognize, paddle_backend.recognize_bytes),
    "doctr": (doctr_backend.recognize, doctr_backend.recognize_bytes),
}


def normalize_engine(engine: str) -> str:
    """Return a validated engine name."""
    name = engine.strip().lower()
    if name not in ENGINES:
        raise ValueError(f"OCR engine must be paddle or doctr, got: {engine}")
    return name


def recognize(
    path: Path, lang: str, engine: str = DEFAULT_ENGINE
) -> list[dict[str, Any]]:
    """Run OCR with the selected backend and return word records."""
    recognize_path, _recognize_bytes = _BACKENDS[normalize_engine(engine)]
    return recognize_path(path, lang)


def recognize_bytes(
    data: bytes, lang: str, engine: str = DEFAULT_ENGINE
) -> list[dict[str, Any]]:
    """Recognize words from file bytes with the selected backend."""
    _recognize_path, recognize_data = _BACKENDS[normalize_engine(engine)]
    return recognize_data(data, lang)
