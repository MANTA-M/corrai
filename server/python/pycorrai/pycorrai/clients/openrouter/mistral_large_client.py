"""Mistral Large OpenRouter client mirroring PHP MistralLargeClient."""

from __future__ import annotations

from .openrouter_client import ConfigurableOpenrouterClient
from .strategies import enable_pdf_parser, mistral_raw_base64_file


class MistralLargeClient(ConfigurableOpenrouterClient):
    def __init__(self) -> None:
        super().__init__("mistralai/mistral-large-2512", mistral_raw_base64_file)

    def add_plugin(self, plugin: str) -> None:
        _ = plugin
        enable_pdf_parser(self, "pdf-text")
