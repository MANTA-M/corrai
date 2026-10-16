"""Backward-compatible re-export; implementation lives in ocr.providers."""

from pycorrai.ocr.providers.mindee import *  # noqa: F403
from pycorrai.ocr.providers.mindee import MindeeOCR

__all__ = ["MindeeOCR"]
