"""Backward-compatible re-export; implementation lives in ocr.providers."""

from pycorrai.ocr.providers.google_vision import *  # noqa: F403
from pycorrai.ocr.providers.google_vision import Vision

__all__ = ["Vision"]
