"""Backward-compatible re-export; implementation lives in ocr.providers."""

from pycorrai.ocr.providers.mistral_ocr import *  # noqa: F403
from pycorrai.ocr.providers.mistral_ocr import MistralOcrClient

__all__ = ["MistralOcrClient"]
