"""Tests for Mistral OCR client payload shaping."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from pycorrai.clients.mistral import MistralOcrClient
from pycorrai.http.rest import AIError, HttpError


class MistralOcrPayloadTest(unittest.TestCase):
    def test_set_file_pdf(self) -> None:
        client = MistralOcrClient(model="mistral-ocr-latest")
        with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as fh:
            fh.write(b"%PDF-1.4 fake")
            path = fh.name
        try:
            client.set_file(path, "doc.pdf")
        finally:
            Path(path).unlink(missing_ok=True)
        doc = client.payload["document"]
        self.assertEqual(doc["type"], "document_url")
        self.assertTrue(doc["document_url"].startswith("data:application/pdf;base64,"))
        self.assertEqual(doc["document_name"], "doc.pdf")

    def test_set_image_url_and_options(self) -> None:
        client = MistralOcrClient()
        client.set_image_url("https://example.com/a.png")
        client.set_pages([0, 1])
        client.set_include_image_base64(True)
        client.set_table_format("html")
        client.set_confidence_scores_granularity("word")
        self.assertEqual(client.payload["document"]["type"], "image_url")
        self.assertEqual(client.payload["pages"], [0, 1])
        self.assertTrue(client.payload["include_image_base64"])
        self.assertEqual(client.payload["table_format"], "html")

    def test_process_requires_document(self) -> None:
        client = MistralOcrClient()
        with self.assertRaises(ValueError):
            client.process()

    def test_process_returns_pages(self) -> None:
        client = MistralOcrClient()
        client.set_document_url("https://example.com/a.pdf")
        fake = {"pages": [{"index": 0, "markdown": "hi"}]}
        with patch.object(client, "query_array", return_value=fake):
            out = client.process()
        self.assertEqual(out["pages"][0]["markdown"], "hi")

    def test_402_raises_ai_error(self) -> None:
        client = MistralOcrClient()
        client.set_file_id("fid")
        with patch.object(client, "query_array", side_effect=HttpError("pay", 402)):
            with self.assertRaises(AIError):
                client.process()


if __name__ == "__main__":
    unittest.main()
