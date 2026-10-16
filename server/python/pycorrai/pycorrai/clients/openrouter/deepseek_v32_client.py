"""DeepSeek V3.2 OpenRouter client mirroring PHP DeepSeekV32Client."""

from __future__ import annotations

from .openrouter_client import ConfigurableOpenrouterClient
from .strategies import pdf_as_file_pdf_text


class DeepSeekV32Client(ConfigurableOpenrouterClient):
    def __init__(self, model: str | None = None) -> None:
        super().__init__(model or "deepseek/deepseek-v3.2", pdf_as_file_pdf_text)
