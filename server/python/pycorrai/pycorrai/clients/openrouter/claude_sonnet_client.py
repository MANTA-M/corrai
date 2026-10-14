"""Claude Sonnet OpenRouter client mirroring PHP ClaudeSonnetClient."""

from __future__ import annotations

import base64
from pathlib import Path

from pycorrai.image.resize import ImageRedimensioner, image_mime_from_bytes, mime_type_for_filename

from .openrouter_client import OpenrouterClient


class ClaudeSonnetClient(OpenrouterClient):
    MAX_IMAGE_DIMENTION = 1568

    def __init__(self) -> None:
        super().__init__("anthropic/claude-sonnet-5.5")
        self.rescale: float = 1.0

    def add_file(self, file_path: str, file_name: str) -> None:
        try:
            data = Path(file_path).read_bytes()
        except OSError as e:
            raise Exception(f"Cannot read file: {file_name}") from e

        mime = mime_type_for_filename(file_name).split(";")[0].strip().lower()
        user_content = self._get_user_content()

        if mime.startswith("image/") or self._image_mime(data) is not None:
            data = self._image_bytes_for_payload(data)
            mime = self._image_mime(data) or mime
            data_url = f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
            user_content.append({"type": "image_url", "image_url": {"url": data_url}})
            return

        if mime != "application/pdf":
            user_content.append(
                {
                    "type": "text",
                    "text": file_name + ":\n" + data.decode("utf-8", errors="replace"),
                }
            )
            return

        data_url = f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
        user_content.append(
            {
                "type": "file",
                "file": {
                    "filename": file_name,
                    "file_data": data_url,
                },
            }
        )

    def _image_bytes_for_payload(self, data: bytes) -> bytes:
        self.rescale = 1.0
        if self._image_mime(data) is None:
            return data
        redimentioner = ImageRedimensioner(self.MAX_IMAGE_DIMENTION, data)
        self.rescale = redimentioner.factor
        return redimentioner.image

    @staticmethod
    def _image_mime(data: bytes) -> str | None:
        return image_mime_from_bytes(data)
