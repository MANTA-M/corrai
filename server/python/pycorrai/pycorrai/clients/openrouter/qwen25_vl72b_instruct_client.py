"""Qwen2.5-VL OpenRouter client mirroring PHP Qwen25Vl72bInstructClient."""

from __future__ import annotations

import base64
from pathlib import Path

from pycorrai.image.resize import mime_type_for_filename

from .openrouter_client import OpenrouterClient


class Qwen25Vl72bInstructClient(OpenrouterClient):
    def __init__(self, model: str | None = None) -> None:
        super().__init__(model or "qwen/qwen2.5-vl-72b-instruct")

    def add_file(self, file_path: str, file_name: str) -> None:
        try:
            data = Path(file_path).read_bytes()
        except OSError as e:
            raise Exception(f"Cannot read file: {file_name}") from e

        mime = mime_type_for_filename(file_name).split(";")[0].strip().lower()
        data_url = f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
        user_content = self._get_user_content()

        if mime == "application/pdf":
            self._enable_pdf_parser()
            user_content.append(
                {
                    "type": "file",
                    "file": {
                        "filename": file_name,
                        "file_data": data_url,
                    },
                }
            )
            return

        if mime.startswith("image/"):
            user_content.append({"type": "image_url", "image_url": {"url": data_url}})
            return

        user_content.append(
            {
                "type": "text",
                "text": file_name + ":\n" + data.decode("utf-8", errors="replace"),
            }
        )

    def _enable_pdf_parser(self) -> None:
        for plugin in self.payload.get("plugins") or []:
            if (plugin.get("id") or "") == "file-parser":
                return
        plugins = self.payload.setdefault("plugins", [])
        plugins.append(
            {
                "id": "file-parser",
                "pdf": {"engine": "mistral-ocr"},
            }
        )
