"""Mistral Large OpenRouter client mirroring PHP MistralLargeClient."""

from __future__ import annotations

import base64
from pathlib import Path

from .openrouter_client import OpenrouterClient


class MistralLargeClient(OpenrouterClient):
    def __init__(self) -> None:
        super().__init__("mistralai/mistral-large")

    def add_plugin(self, plugin: str) -> None:
        _ = plugin
        if "plugins" not in self.payload:
            self.payload["plugins"] = []
        for existing in self.payload["plugins"]:
            if existing.get("id") == "file-parser":
                return
        self.payload["plugins"].append(
            {
                "id": "file-parser",
                "pdf": {
                    "engine": "pdf-text",  # defaults to "mistral-ocr". See Pricing above
                },
            }
        )

    def add_file(self, file_path: str, file_name: str) -> None:
        # PHP: pathinfo($file_path, PATHINFO_EXTENSION) === 'pdf'
        if Path(file_path).suffix == ".pdf":
            self.add_plugin("file-parser")
        # RAW base64 (no data: prefix), always type:file
        file_data = base64.b64encode(Path(file_path).read_bytes()).decode("ascii")
        user_content = self._get_user_content()
        user_content.append(
            {
                "type": "file",
                "file": {"filename": file_name, "file_data": file_data},
            }
        )
