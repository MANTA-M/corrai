"""Gemini Flash OpenRouter client mirroring PHP GeminiFlashClient."""

from __future__ import annotations

from .openrouter_client import ConfigurableOpenrouterClient
from .strategies import image_or_pdf_as_image_url


class GeminiFlashClient(ConfigurableOpenrouterClient):
    def __init__(self, model: str | None = None) -> None:
        super().__init__(model or "google/gemini-3.8-flash", image_or_pdf_as_image_url)
