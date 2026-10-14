"""Gemini Flash OpenRouter client mirroring PHP GeminiFlashClient."""

from __future__ import annotations

import base64
from pathlib import Path

from pycorrai.image.resize import mime_type_for_filename

from .openrouter_client import OpenrouterClient


class GeminiFlashClient(OpenrouterClient):
    def __init__(self, model: str | None = None) -> None:
        super().__init__(model or "google/gemini-3.8-flash")

    def add_file(self, file_path: str, file_name: str) -> None:
        try:
            data = Path(file_path).read_bytes()
        except OSError as e:
            raise Exception(f"Cannot read file: {file_name}") from e

        mime = mime_type_for_filename(file_name).split(";")[0].strip().lower()
        user_content = self._get_user_content()
        if mime.startswith("image/"):
            user_content.append(
                {
                    "type": "image_url",
                    "image_url": {
                        "url": f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
                    },
                }
            )
            return

        user_content.append(
            {
                "type": "image_url",
                "image_url": {
                    "url": "data:application/pdf;base64," + base64.b64encode(data).decode("ascii")
                },
            }
        )
