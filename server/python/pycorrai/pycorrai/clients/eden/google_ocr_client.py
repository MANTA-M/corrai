"""Backward-compatible re-export; implementation lives in ocr.providers."""

from pycorrai.ocr.providers.eden_google_ocr import *  # noqa: F403
from pycorrai.ocr.providers.eden_google_ocr import GoogleOCRClient

__all__ = ["GoogleOCRClient"]
