"""Abstract OpenRouter LLM client mirroring PHP OpenrouterClient."""

from __future__ import annotations

import base64
import json
import re
from abc import ABC, abstractmethod
from collections.abc import Callable
from typing import Any

from pycorrai.config import ensure_env_loaded, env
from pycorrai.http.rest import AIError, LlmClient, raise_ai_on_402

_DATA_URL_RE = re.compile(r"^data:([^;]+);base64,(.+)$", re.DOTALL)

AddFileStrategy = Callable[["OpenrouterClient", str, str], None]


class OpenrouterClient(LlmClient, ABC):
    OPENROUTER_API_URL = "https://openrouter.ai/api/v1/chat/completions"

    def __init__(self, model: str):
        ensure_env_loaded()
        super().__init__("", env("OPENROUTER_API_KEY"))
        self.payload: dict[str, Any] = {
            "model": model,
            "messages": [],
        }

    def _get_user_content(self) -> list[Any]:
        for message in self.payload["messages"]:
            if message["role"] == "user":
                return message["content"]
        user_content: list[Any] = []
        self.payload["messages"].append({"role": "user", "content": user_content})
        return user_content

    def set_system_content(self, content: str) -> None:
        for message in self.payload["messages"]:
            if message["role"] == "system":
                message["content"] = content
                return
        self.payload["messages"].append({"role": "system", "content": content})

    def add_text(self, text: str) -> None:
        user_content = self._get_user_content()
        user_content.append({"type": "text", "text": text})

    @abstractmethod
    def add_file(self, file_path: str, file_name: str) -> None:
        """Add a file to the payload (model-specific)."""

    def enable_image_output(self) -> None:
        self.payload["modalities"] = ["image", "text"]

    def set_json_response(self, name: str, schema: dict[str, Any]) -> None:
        self.payload["response_format"] = {
            "type": "json_schema",
            "json_schema": {
                "name": name,
                "strict": True,
                "schema": schema,
            },
        }
        provider = self.payload.get("provider")
        if not isinstance(provider, dict):
            provider = {}
        provider["require_parameters"] = True
        self.payload["provider"] = provider

    def call_text(self) -> str:
        text, _cost = self.call_text_with_cost()
        return text

    def call_text_with_cost(self) -> tuple[str, float]:
        """Call OpenRouter and return assistant text plus usage cost in USD."""
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
        images: list[dict[str, str]] = []
        for image in message.get("images") or []:
            url = ""
            if isinstance(image, dict):
                image_url = image.get("image_url")
                if isinstance(image_url, dict):
                    url = image_url.get("url") or ""
                elif isinstance(image_url, str):
                    url = image_url
            decoded = self._decode_data_url(str(url))
            if decoded is not None:
                images.append(decoded)

        if not images:
            content = message.get("content")
            if isinstance(content, list):
                for part in content:
                    if not isinstance(part, dict):
                        continue
                    image_url = part.get("image_url")
                    url = ""
                    if isinstance(image_url, dict):
                        url = image_url.get("url") or ""
                    decoded = self._decode_data_url(str(url))
                    if decoded is not None:
                        images.append(decoded)

        return {"text": text, "images": images}

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
                continue
            if isinstance(part, dict) and isinstance(part.get("text"), str):
                parts.append(part["text"])
        return "\n".join(parts)

    @staticmethod
    def _decode_data_url(url: str) -> dict[str, str] | None:
        if url == "":
            return None
        match = _DATA_URL_RE.match(url)
        if not match:
            return None
        try:
            body = base64.b64decode(match.group(2), validate=True)
        except Exception:
            return None
        return {"mime": match.group(1), "body": body}

    def call(self) -> dict[str, Any]:
        try:
            respone = self._query_model()
            json_content = respone["choices"][0]["message"]["content"]
            cleaned = str(json_content).replace("```", "").replace("json", "")
            if not cleaned:
                raise Exception("Invalid JSON content: " + str(json_content))
            try:
                response = json.loads(cleaned)
            except json.JSONDecodeError as e:
                raise Exception(str(e)) from e
            if response is None:
                raise Exception("Invalid JSON content: " + str(json_content))
            return {
                "model": self.payload["model"],
                "response": response,
                "payload": self.payload,
                "respone": respone,
            }
        except AIError:
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
                self.OPENROUTER_API_URL,
                "POST",
                self.common_headers,
                self.payload,
            )
            if result is None:
                return None
            if not isinstance(result, dict):
                raise Exception("Invalid model response")
            return result
        except Exception as th:
            raise_ai_on_402(th)
            raise  # pragma: no cover — raise_ai_on_402 always raises


class ConfigurableOpenrouterClient(OpenrouterClient):
    """OpenRouter client configured with a model name and an add_file strategy."""

    def __init__(self, model: str, add_file_strategy: AddFileStrategy) -> None:
        super().__init__(model)
        self._add_file_strategy = add_file_strategy

    def add_file(self, file_path: str, file_name: str) -> None:
        self._add_file_strategy(self, file_path, file_name)
