"""Qwen2.5-VL OpenRouter client mirroring PHP Qwen25Vl72bInstructClient."""

from __future__ import annotations

from .openrouter_client import ConfigurableOpenrouterClient
from .strategies import pdf_as_file_mistral_ocr


class Qwen25Vl72bInstructClient(ConfigurableOpenrouterClient):
    def __init__(self, model: str | None = None) -> None:
        super().__init__(model or "qwen/qwen2.5-vl-72b-instruct", pdf_as_file_mistral_ocr)
