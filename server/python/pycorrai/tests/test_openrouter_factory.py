"""Tests for OpenRouter factory routing and chat payload helpers."""

from __future__ import annotations

import unittest
from unittest.mock import patch

from pycorrai.clients.openrouter import LlmClientFactory
from pycorrai.clients.openrouter.claude_sonnet_client import ClaudeSonnetClient
from pycorrai.clients.openrouter.deepseek_v32_client import DeepSeekV32Client
from pycorrai.clients.openrouter.gemini3_client import Gemini3Client
from pycorrai.clients.openrouter.gemini_flash_client import GeminiFlashClient
from pycorrai.clients.openrouter.gemini_flash_lite_client import GeminiFlashLiteClient
from pycorrai.clients.openrouter.gpt4o_mini_client import Gpt4oMiniClient
from pycorrai.clients.openrouter.gpt61_sol_client import Gpt61SolClient
from pycorrai.clients.openrouter.mistral_large_client import MistralLargeClient
from pycorrai.clients.openrouter.qwen25_vl72b_instruct_client import (
    Qwen25Vl72bInstructClient,
)
from pycorrai.http.rest import AIError, HttpError


class FactoryRoutingTest(unittest.TestCase):
    def test_routes(self) -> None:
        cases = [
            ("qwen/foo", Qwen25Vl72bInstructClient),
            ("deepseek/v3", DeepSeekV32Client),
            ("openai/gpt-6.1-sol", Gpt61SolClient),
            ("openai/gpt-4o-mini", Gpt4oMiniClient),
            ("anthropic/claude-3", ClaudeSonnetClient),
            ("google/gemini-3.1-flash-lite", GeminiFlashLiteClient),
            ("google/gemini-3.8-flash", GeminiFlashClient),
            ("google/gemini-3.1-pro-preview", Gemini3Client),
            ("mistralai/mistral-large", MistralLargeClient),
        ]
        for model, cls in cases:
            with self.subTest(model=model):
                client = LlmClientFactory.create(model)
                self.assertIsInstance(client, cls)

    def test_unsupported(self) -> None:
        with self.assertRaises(Exception) as ctx:
            LlmClientFactory.create("unknown/model")
        self.assertIn("not supported", str(ctx.exception))


class OpenrouterPayloadTest(unittest.TestCase):
    def test_system_text_and_json_schema(self) -> None:
        client = LlmClientFactory.create("openai/gpt-4o-mini")
        client.set_system_content("sys")
        client.add_text("hello")
        client.set_json_response("out", {"type": "object", "properties": {}})
        roles = [m["role"] for m in client.payload["messages"]]
        self.assertIn("system", roles)
        self.assertIn("user", roles)
        self.assertEqual(client.payload["response_format"]["type"], "json_schema")
        self.assertTrue(client.payload["provider"]["require_parameters"])

    def test_call_text_uses_query(self) -> None:
        client = LlmClientFactory.create("openai/gpt-4o-mini")
        client.add_text("hi")
        fake = {
            "choices": [{"message": {"content": "answer"}}],
        }
        with patch.object(client, "query_array", return_value=fake):
            self.assertEqual(client.call_text(), "answer")

    def test_call_text_with_cost(self) -> None:
        client = LlmClientFactory.create("openai/gpt-4o-mini")
        client.add_text("hi")
        fake = {
            "choices": [{"message": {"content": "answer"}}],
            "usage": {"cost": 0.0042},
        }
        with patch.object(client, "query_array", return_value=fake):
            text, cost = client.call_text_with_cost()
        self.assertEqual(text, "answer")
        self.assertEqual(cost, 0.0042)

    def test_402_raises_ai_error(self) -> None:
        client = LlmClientFactory.create("openai/gpt-4o-mini")
        client.add_text("hi")
        with patch.object(client, "query_array", side_effect=HttpError("pay", 402)):
            with self.assertRaises(AIError):
                client.call_text()

    def test_mistral_large_pdf_uses_raw_base64_file(self) -> None:
        client = MistralLargeClient()
        with tempfile_named(b"%PDF-1.4", suffix=".pdf") as path:
            client.add_file(path, "doc.pdf")
        part = client.payload["messages"][0]["content"][0]
        self.assertEqual(part["type"], "file")
        self.assertFalse(part["file"]["file_data"].startswith("data:"))
        self.assertTrue(
            any(p.get("id") == "file-parser" for p in client.payload.get("plugins", []))
        )

    def test_mistral_large_image_uses_image_url(self) -> None:
        import io

        from PIL import Image

        buf = io.BytesIO()
        Image.new("RGB", (2, 2), (255, 0, 0)).save(buf, format="JPEG")
        client = MistralLargeClient()
        with tempfile_named(buf.getvalue(), suffix=".jpg") as path:
            client.add_file(path, "page.jpg")
        part = client.payload["messages"][0]["content"][0]
        self.assertEqual(part["type"], "image_url")
        self.assertTrue(part["image_url"]["url"].startswith("data:image/jpeg;base64,"))


class _TempFile:
    def __init__(self, data: bytes, suffix: str):
        self.data = data
        self.suffix = suffix
        self.path = ""

    def __enter__(self) -> str:
        import tempfile

        fh = tempfile.NamedTemporaryFile(delete=False, suffix=self.suffix)
        fh.write(self.data)
        fh.close()
        self.path = fh.name
        return self.path

    def __exit__(self, *args: object) -> None:
        import os

        if self.path:
            os.unlink(self.path)


def tempfile_named(data: bytes, suffix: str) -> _TempFile:
    return _TempFile(data, suffix)


if __name__ == "__main__":
    unittest.main()
