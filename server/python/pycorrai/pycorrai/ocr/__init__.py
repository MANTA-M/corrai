"""OCR backends (PaddleOCR, python-doctr, Google Vision, Gemini Flash)."""

from pycorrai.ocr.dispatch import DEFAULT_ENGINE, ENGINES, recognize, recognize_bytes
from pycorrai.ocr.gemini_flash_ocr import GeminiFlashOcr, gemini_flash_ocr
from pycorrai.ocr.google_vision_ocr import GoogleVisionOcr, google_vision_ocr

__all__ = [
    "DEFAULT_ENGINE",
    "ENGINES",
    "GeminiFlashOcr",
    "gemini_flash_ocr",
    "GoogleVisionOcr",
    "google_vision_ocr",
    "recognize",
    "recognize_bytes",
]
