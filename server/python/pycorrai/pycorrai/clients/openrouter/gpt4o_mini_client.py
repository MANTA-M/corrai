"""GPT-4o Mini OpenRouter client mirroring PHP Gpt4oMiniClient."""

from __future__ import annotations

from .openrouter_client import ConfigurableOpenrouterClient
from .strategies import pdf_as_file_mistral_ocr


class Gpt4oMiniClient(ConfigurableOpenrouterClient):
    def __init__(self, model: str | None = None) -> None:
        super().__init__(model or "openai/gpt-4o-mini", pdf_as_file_mistral_ocr)
