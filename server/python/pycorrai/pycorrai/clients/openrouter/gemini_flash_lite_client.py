"""Gemini Flash Lite OpenRouter client mirroring PHP GeminiFlashLiteClient."""

from __future__ import annotations

import base64
from pathlib import Path

from pycorrai.image.resize import image_mime_from_bytes, mime_type_for_filename

from .openrouter_client import OpenrouterClient


class GeminiFlashLiteClient(OpenrouterClient):
    def __init__(self) -> None:
        super().__init__("google/gemini-3.1-flash-lite")

    def add_file(self, file_path: str, file_name: str) -> None:
        try:
            data = Path(file_path).read_bytes()
        except OSError as e:
            raise Exception(f"Cannot read file: {file_name}") from e
        if data == b"":
            raise Exception(f"Cannot read file: {file_name}")

        mime = mime_type_for_filename(file_name).split(";")[0].strip().lower()
        image_mime = self._image_mime(data, mime)
        user_content = self._get_user_content()

        if image_mime is not None:
            encoded = base64.b64encode(data).decode("ascii")
            decoded = base64.b64decode(encoded)
            if decoded != data:
                raise Exception(f"Incomplete image payload: {file_name}")
            user_content.append(
                {
                    "type": "image_url",
                    "image_url": {"url": f"data:{image_mime};base64,{encoded}"},
                }
            )
            return

        if mime == "application/pdf" or data.startswith(b"%PDF"):
            raise Exception(
                "Gemini Flash Lite image_url accepts images only. "
                "Render the PDF page to JPEG or PNG before sending."
            )

        user_content.append(
            {
                "type": "text",
                "text": file_name + ":\n" + data.decode("utf-8", errors="replace"),
            }
        )

    @staticmethod
    def _image_mime(data: bytes, filename_mime: str) -> str | None:
        detected = image_mime_from_bytes(data)
        if filename_mime.startswith("image/"):
            if detected is None:
                raise Exception("Image payload is empty or corrupted")
            return filename_mime
        return detected
