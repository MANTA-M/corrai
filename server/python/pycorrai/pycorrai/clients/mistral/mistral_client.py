"""Direct Mistral chat client mirroring PHP Mistral/MistralClient.php."""

from __future__ import annotations

import base64
import json
import logging
import re
from typing import Any

from pycorrai.config import ensure_env_loaded, env
from pycorrai.http.rest import AIError, HttpError, LlmClient, raise_ai_on_402
from pycorrai.image.resize import ImageRedimensioner, image_mime_from_bytes, mime_type_for_filename

logger = logging.getLogger(__name__)


class MistralClient(LlmClient):
    """Posts to https://api.mistral.ai/v1/chat/completions using MISTRAL_API_KEY."""

    MISTRAL_API_URL = "https://api.mistral.ai/v1/chat/completions"
    MAX_IMAGE_DIMENTION = 1568

    def __init__(self, model: str | None = None) -> None:
        ensure_env_loaded()
        super().__init__("", env("MISTRAL_API_KEY", ""))
        self.rescale = 1.0
        self.payload: dict[str, Any] = {
            "model": model or env("MISTRAL_MODEL", "mistral-large-latest"),
            "messages": [],
        }

    def _get_user_content(self) -> list[Any]:
        for message in self.payload["messages"]:
            if message.get("role") == "user":
                content = message.get("content")
                if not isinstance(content, list):
                    content = []
                    message["content"] = content
                return content
        user_content: list[Any] = []
        self.payload["messages"].append({"role": "user", "content": user_content})
        return self.payload["messages"][-1]["content"]

    def set_system_content(self, content: str) -> None:
        for message in self.payload["messages"]:
            if message.get("role") == "system":
                message["content"] = content
                return
        self.payload["messages"].append({"role": "system", "content": content})

    def add_text(self, text: str) -> None:
        self._get_user_content().append({"type": "text", "text": text})

    def add_file(self, file_path: str, file_name: str) -> None:
        try:
            with open(file_path, "rb") as fh:
                data = fh.read()
        except OSError as exc:
            raise Exception(f"Cannot read file: {file_name}") from exc

        mime = mime_type_for_filename(file_name).split(";", 1)[0].strip().lower()
        user_content = self._get_user_content()

        if mime.startswith("image/") or self._image_mime(data) is not None:
            data = self._image_bytes_for_payload(data)
            mime = self._image_mime(data) or mime
            data_url = f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
            user_content.append({"type": "image_url", "image_url": {"url": data_url}})
            return

        if mime != "application/pdf":
            user_content.append(
                {"type": "text", "text": f"{file_name}:\n" + data.decode("utf-8", errors="replace")}
            )
            return

        data_url = f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
        user_content.append(
            {
                "type": "document_url",
                "document_url": data_url,
                "document_name": file_name,
            }
        )

    def enable_image_output(self) -> None:
        tools = self.payload.get("tools")
        if not isinstance(tools, list):
            tools = []
        for tool in tools:
            if isinstance(tool, dict) and tool.get("type") == "image_generation":
                return
        tools.append({"type": "image_generation"})
        self.payload["tools"] = tools

    def set_json_response(self, name: str, schema: dict[str, Any]) -> None:
        self.payload["response_format"] = {
            "type": "json_schema",
            "json_schema": {
                "name": name,
                "strict": True,
                "schema": schema,
            },
        }

    def call_text(self) -> str:
        text, _cost = self.call_text_with_cost()
        return text

    def call_text_with_cost(self) -> tuple[str, float]:
        """Call Mistral and return assistant text plus usage cost in USD.

        Direct Mistral responses expose token usage but not a USD cost field;
        cost is therefore reported as ``0.0`` unless ``usage.cost`` is present.
        """
        response = self._query_model()
        if response is None:
            raise Exception("Empty response from model")
        message = (response.get("choices") or [{}])[0].get("message")
        if not isinstance(message, dict):
            raise Exception("Invalid model response")
        text = self._extract_message_text(message.get("content", ""))
        if text.strip() == "":
            raise Exception("Empty text response from model")
        return text, self._usage_cost(response)

    @staticmethod
    def _usage_cost(response: dict[str, Any]) -> float:
        usage = response.get("usage")
        if not isinstance(usage, dict):
            return 0.0
        raw_cost = usage.get("cost")
        if isinstance(raw_cost, (int, float)) and not isinstance(raw_cost, bool):
            return float(raw_cost)
        if isinstance(raw_cost, str) and raw_cost.strip() != "":
            try:
                return float(raw_cost)
            except ValueError:
                return 0.0
        return 0.0

    def call_annotation(self) -> dict[str, Any]:
        response = self._query_model()
        if response is None:
            raise Exception("Empty response from model")
        message = (response.get("choices") or [{}])[0].get("message")
        if not isinstance(message, dict):
            raise Exception("Invalid model response")

        text = self._extract_message_text(message.get("content", ""))
        images: list[dict[str, Any]] = []
        content = message.get("content")
        if isinstance(content, list):
            for part in content:
                if not isinstance(part, dict) or part.get("type") != "image_url":
                    continue
                image_url = part.get("image_url")
                if isinstance(image_url, dict):
                    url = image_url.get("url") or ""
                elif isinstance(image_url, str):
                    url = image_url
                else:
                    url = ""
                decoded = self._decode_data_url(str(url))
                if decoded is not None:
                    images.append(decoded)
        return {"text": text, "images": images}

    def call(self) -> dict[str, Any]:
        try:
            respone = self._query_model()
            if respone is None:
                raise Exception("Empty response from model")
            json_content = (respone.get("choices") or [{}])[0].get("message", {}).get("content")
            if isinstance(json_content, list):
                json_content = self._extract_message_text(json_content)
            cleaned = str(json_content).replace("```", "").replace("json", "")
            try:
                response = json.loads(cleaned)
            except json.JSONDecodeError:
                response = None
            if response is None:
                raise Exception(f"Invalid JSON content: {json_content}")
            return {
                "model": self.payload["model"],
                "response": response,
                "payload": self.payload,
                "respone": respone,
            }
        except (HttpError, AIError):
            raise
        except Exception as th:
            return {
                "model": self.payload["model"],
                "response": str(th),
                "payload": self.payload,
                "respone": None,
            }

    def _query_model(self) -> dict[str, Any] | None:
        try:
            result = self.query_array(
                self.MISTRAL_API_URL, "POST", self.common_headers, self.payload
            )
            return result if isinstance(result, dict) else None
        except Exception as th:
            raise_ai_on_402(th)

    @staticmethod
    def _extract_message_text(content: Any) -> str:
        if isinstance(content, str):
            return content
        if not isinstance(content, list):
            return ""
        parts: list[str] = []
        for part in content:
            if isinstance(part, str):
                parts.append(part)
            elif isinstance(part, dict) and isinstance(part.get("text"), str):
                parts.append(part["text"])
        return "\n".join(parts)

    @staticmethod
    def _decode_data_url(url: str) -> dict[str, Any] | None:
        if not url:
            return None
        match = re.match(r"^data:([^;]+);base64,(.+)$", url, re.DOTALL)
        if not match:
            return None
        try:
            body = base64.b64decode(match.group(2), validate=True)
        except Exception:
            return None
        return {"mime": match.group(1), "body": body}

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
