"""Gemini Flash Lite OpenRouter client mirroring PHP GeminiFlashLiteClient."""

from __future__ import annotations

from .openrouter_client import ConfigurableOpenrouterClient
from .strategies import gemini_flash_lite_image_or_text


class GeminiFlashLiteClient(ConfigurableOpenrouterClient):
    def __init__(self) -> None:
        super().__init__("google/gemini-3.1-flash-lite", gemini_flash_lite_image_or_text)
