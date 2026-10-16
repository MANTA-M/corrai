"""Gemini 3 OpenRouter client mirroring PHP Gemini3Client."""

from __future__ import annotations

from .openrouter_client import ConfigurableOpenrouterClient
from .strategies import pdf_as_file_mistral_ocr


class Gemini3Client(ConfigurableOpenrouterClient):
    def __init__(self, model: str | None = None) -> None:
        super().__init__(model or "google/gemini-3.1-pro-preview", pdf_as_file_mistral_ocr)
