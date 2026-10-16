"""GPT-6.1 Sol OpenRouter client."""

from __future__ import annotations

from .openrouter_client import ConfigurableOpenrouterClient
from .strategies import pdf_as_file_mistral_ocr


class Gpt61SolClient(ConfigurableOpenrouterClient):
    def __init__(self, model: str | None = None) -> None:
        super().__init__(model or "openai/gpt-6.1-sol", pdf_as_file_mistral_ocr)
