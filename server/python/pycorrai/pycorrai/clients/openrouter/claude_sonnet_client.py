"""Claude Sonnet OpenRouter client mirroring PHP ClaudeSonnetClient."""

from __future__ import annotations

from .openrouter_client import ConfigurableOpenrouterClient
from .strategies import claude_resized_image_or_pdf_file_or_text


class ClaudeSonnetClient(ConfigurableOpenrouterClient):
    MAX_IMAGE_DIMENTION = 1568

    def __init__(self) -> None:
        super().__init__(
            "anthropic/claude-sonnet-5.5", claude_resized_image_or_pdf_file_or_text
        )
        self.rescale: float = 1.0
