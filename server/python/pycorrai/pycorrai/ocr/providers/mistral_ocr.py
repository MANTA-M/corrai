"""Direct Mistral OCR client mirroring PHP Mistral/MistralOcrClient.php."""

from __future__ import annotations

import base64
from typing import Any

from pycorrai.config import ensure_env_loaded, env
from pycorrai.http.rest import LlmClient, raise_ai_on_402
from pycorrai.image.resize import ImageRedimensioner, image_mime_from_bytes, mime_type_for_filename


class MistralOcrClient(LlmClient):
    """Posts to https://api.mistral.ai/v1/ocr using MISTRAL_API_KEY."""

    MISTRAL_OCR_URL = "https://api.mistral.ai/v1/ocr"
    MAX_IMAGE_DIMENTION = 1568

    def __init__(self, model: str | None = None) -> None:
        ensure_env_loaded()
        super().__init__("", env("MISTRAL_API_KEY", ""))
        self.rescale = 1.0
        self.payload: dict[str, Any] = {
            "model": model or env("MISTRAL_OCR_MODEL", "mistral-ocr-latest"),
        }

    def set_file(self, file_path: str, file_name: str) -> None:
        try:
            with open(file_path, "rb") as fh:
                data = fh.read()
        except OSError as exc:
            raise Exception(f"Cannot read file: {file_name}") from exc

        mime = mime_type_for_filename(file_name).split(";", 1)[0].strip().lower()

        if mime.startswith("image/") or self._image_mime(data) is not None:
            data = self._image_bytes_for_payload(data)
            mime = self._image_mime(data) or mime
            self.payload["document"] = {
                "type": "image_url",
                "image_url": f"data:{mime};base64," + base64.b64encode(data).decode("ascii"),
            }
            return

        if mime != "application/pdf":
            raise ValueError(f"Unsupported OCR file type: {file_name}")

        self.payload["document"] = {
            "type": "document_url",
            "document_url": f"data:{mime};base64," + base64.b64encode(data).decode("ascii"),
            "document_name": file_name,
        }

    def set_document_url(self, url: str, name: str | None = None) -> None:
        document: dict[str, Any] = {
            "type": "document_url",
            "document_url": url,
        }
        if name is not None and name != "":
            document["document_name"] = name
        self.payload["document"] = document

    def set_image_url(self, url: str) -> None:
        self.payload["document"] = {
            "type": "image_url",
            "image_url": url,
        }

    def set_file_id(self, file_id: str) -> None:
        self.payload["document"] = {
            "type": "file",
            "file_id": file_id,
        }

    def set_pages(self, pages: str | list[int]) -> None:
        if isinstance(pages, list):
            for page in pages:
                if not isinstance(page, int):
                    raise ValueError("Page numbers must be integers")
        elif pages.strip() == "":
            raise ValueError("Pages must not be empty")
        self.payload["pages"] = pages

    def set_include_image_base64(self, include: bool) -> None:
        self.payload["include_image_base64"] = include

    def set_include_blocks(self, include: bool) -> None:
        self.payload["include_blocks"] = include

    def set_extract_header(self, extract: bool) -> None:
        self.payload["extract_header"] = extract

    def set_extract_footer(self, extract: bool) -> None:
        self.payload["extract_footer"] = extract

    def set_table_format(self, format: str) -> None:
        if format not in ("markdown", "html"):
            raise ValueError("Table format must be markdown or html")
        self.payload["table_format"] = format

    def set_image_limit(self, limit: int) -> None:
        self.payload["image_limit"] = limit

    def set_image_min_size(self, size: int) -> None:
        self.payload["image_min_size"] = size

    def set_confidence_scores_granularity(self, granularity: str) -> None:
        if granularity not in ("word", "page", "block"):
            raise ValueError("Confidence granularity must be word, page, or block")
        self.payload["confidence_scores_granularity"] = granularity

    def process(self) -> dict[str, Any]:
        document = self.payload.get("document")
        if not isinstance(document, dict):
            raise ValueError("OCR document is required")

        response = self._query_model()
        pages = response.get("pages") if isinstance(response, dict) else None
        if response is None or not isinstance(pages, list) or pages == []:
            raise Exception("Empty OCR response")
        return response

    def _query_model(self) -> dict[str, Any] | None:
        try:
            result = self.query_array(
                self.MISTRAL_OCR_URL, "POST", self.common_headers, self.payload
            )
            return result if isinstance(result, dict) else None
        except Exception as th:
            raise_ai_on_402(th)

    def _image_bytes_for_payload(self, data: bytes) -> bytes:
        self.rescale = 1.0
        if image_mime_from_bytes(data) is None:
            return data
        try:
            redimentioner = ImageRedimensioner(self.MAX_IMAGE_DIMENTION, data)
        except ValueError:
            return data
        self.rescale = redimentioner.factor
        return redimentioner.image

    def _image_mime(self, data: bytes) -> str | None:
        return image_mime_from_bytes(data)
