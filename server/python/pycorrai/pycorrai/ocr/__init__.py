"""OCR backends (PaddleOCR and python-doctr)."""

from pycorrai.ocr.dispatch import DEFAULT_ENGINE, ENGINES, recognize, recognize_bytes

__all__ = [
    "DEFAULT_ENGINE",
    "ENGINES",
    "recognize",
    "recognize_bytes",
]
