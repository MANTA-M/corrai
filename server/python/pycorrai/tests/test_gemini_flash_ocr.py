"""Unit tests for gemini_flash_ocr."""

from __future__ import annotations

import io
import json
import unittest
from unittest.mock import AsyncMock, MagicMock, patch

from PIL import Image

from pycorrai.clients.openrouter.gemini_flash_client import GeminiFlashClient
from pycorrai.model.submission_file import SubmissionFile
from pycorrai.ocr.gemini_flash_ocr import (
    EVENT_FAILED,
    EVENT_START,
    STATUS_ERROR,
    STATUS_TRANSCRIBED,
    GeminiFlashOcr,
    gemini_flash_ocr,
)


def _file(
    file_id: str = "f1",
    *,
    name: str = "f1.png",
    student: str | None = None,
) -> SubmissionFile:
    f = SubmissionFile()
    f.id = file_id
    f.school_id = "s1"
    f.user_id = "t1"
    f.assessment_id = "a1"
    f.name = name
    f.size = 100
    f.content_type = "image/png"
    f.status = "stored"
    f.student = student
    return f


def _png_bytes() -> bytes:
    buf = io.BytesIO()
    Image.new("RGB", (8, 8), color=(255, 0, 0)).save(buf, format="PNG")
    return buf.getvalue()


def _gemini_http_body(*, text: str = "hello", cost: float = 0.0012) -> dict:
    return {
        "choices": [
            {
                "message": {
                    "content": json.dumps(
                        {"text": text, "caligraphy_quality": 0.8}
                    )
                }
            }
        ],
        "usage": {"cost": cost, "prompt_tokens": 10, "completion_tokens": 5},
    }


class GeminiFlashOcrTest(unittest.TestCase):
    def test_skips_when_ocr_result_exists_without_force(self) -> None:
        file = _file()
        store = MagicMock()
        store.exists.return_value = True
        factory = MagicMock()

        with patch(
            "pycorrai.ocr.gemini_flash_ocr.ObjectStore.get_instance",
            return_value=store,
        ):
            GeminiFlashOcr(client_factory=factory).gemini_flash_ocr([file])

        factory.assert_not_called()
        self.assertEqual(file.status, "stored")

    def test_force_re_ocr_updates_existing_text_only(self) -> None:
        file = _file()
        store = MagicMock()
        store.exists.side_effect = lambda key: key.endswith("ocr_result.json")
        store.get_json.return_value = {
            "text": "old",
            "words": [{"text": "old", "page": 0, "left": 0, "top": 0, "right": 1, "bottom": 1}],
            "rotation": 90,
        }
        store.download_to_temp.return_value = "/tmp/fake.png"

        client = GeminiFlashClient()
        client.token = "tok"
        client.disable_ssl_verif = True
        client.timeout = 30

        response = MagicMock()
        response.status_code = 200
        response.json.return_value = _gemini_http_body(text="new text", cost=0.0025)
        response.text = "ok"

        http = AsyncMock()
        http.post = AsyncMock(return_value=response)
        async_cm = MagicMock()
        async_cm.__aenter__ = AsyncMock(return_value=http)
        async_cm.__aexit__ = AsyncMock(return_value=None)

        with (
            patch(
                "pycorrai.ocr.gemini_flash_ocr.ObjectStore.get_instance",
                return_value=store,
            ),
            patch.object(file, "save_attributes"),
            patch.object(file, "append_event") as append_event,
            patch(
                "pycorrai.ocr.gemini_flash_ocr.publish_file_status"
            ) as publish,
            patch(
                "pycorrai.ocr.gemini_flash_ocr._prepare_upload",
                return_value=("/tmp/fake.jpg", "f1.jpg"),
            ),
            patch(
                "pycorrai.ocr.gemini_flash_ocr.httpx.AsyncClient",
                return_value=async_cm,
            ),
            patch(
                "pycorrai.ocr.gemini_flash_ocr.GeminiFlashClient.add_file"
            ),
        ):
            GeminiFlashOcr(client_factory=lambda: client).gemini_flash_ocr(
                [file], force_re_ocr=True
            )

        self.assertEqual(file.status, STATUS_TRANSCRIBED)
        store.put_contents.assert_called()
        body = store.put_contents.call_args.args[1]
        saved = json.loads(body)
        self.assertEqual(saved["text"], "new text")
        self.assertEqual(saved["rotation"], 90)
        self.assertEqual(len(saved["words"]), 1)
        event_names = [c.args[0] for c in append_event.call_args_list]
        self.assertEqual(event_names[0], EVENT_START)
        self.assertTrue(event_names[-1].startswith("Transcription ("))
        self.assertIn("0.0025 USD", event_names[-1])
        self.assertEqual(publish.call_args_list[0].kwargs["loading"], True)
        self.assertEqual(publish.call_args_list[-1].kwargs["loading"], False)
        self.assertEqual(file.status, STATUS_TRANSCRIBED)

    def test_creates_ocr_result_when_missing(self) -> None:
        file = _file()
        store = MagicMock()
        store.exists.return_value = False
        store.download_to_temp.return_value = "/tmp/fake.png"

        client = GeminiFlashClient()
        client.token = "tok"
        client.disable_ssl_verif = True
        client.timeout = 30

        response = MagicMock()
        response.status_code = 200
        response.json.return_value = _gemini_http_body(text="fresh")
        response.text = "ok"

        http = AsyncMock()
        http.post = AsyncMock(return_value=response)
        async_cm = MagicMock()
        async_cm.__aenter__ = AsyncMock(return_value=http)
        async_cm.__aexit__ = AsyncMock(return_value=None)

        with (
            patch(
                "pycorrai.ocr.gemini_flash_ocr.ObjectStore.get_instance",
                return_value=store,
            ),
            patch.object(file, "save_attributes"),
            patch.object(file, "append_event"),
            patch("pycorrai.ocr.gemini_flash_ocr.publish_file_status"),
            patch(
                "pycorrai.ocr.gemini_flash_ocr._prepare_upload",
                return_value=("/tmp/fake.jpg", "f1.jpg"),
            ),
            patch(
                "pycorrai.ocr.gemini_flash_ocr.httpx.AsyncClient",
                return_value=async_cm,
            ),
            patch(
                "pycorrai.ocr.gemini_flash_ocr.GeminiFlashClient.add_file"
            ),
        ):
            GeminiFlashOcr(client_factory=lambda: client).gemini_flash_ocr([file])

        body = store.put_contents.call_args.args[1]
        saved = json.loads(body)
        self.assertEqual(saved["text"], "fresh")
        self.assertEqual(saved["words"], [])
        self.assertEqual(file.status, STATUS_TRANSCRIBED)

    def test_error_sets_status_and_publishes_loading_false(self) -> None:
        file = _file()
        store = MagicMock()
        store.exists.return_value = False
        store.download_to_temp.return_value = "/tmp/fake.png"

        client = GeminiFlashClient()
        client.token = "tok"
        client.disable_ssl_verif = True
        client.timeout = 30

        response = MagicMock()
        response.status_code = 500
        response.text = "boom"
        response.json.side_effect = Exception("no json")

        http = AsyncMock()
        http.post = AsyncMock(return_value=response)
        async_cm = MagicMock()
        async_cm.__aenter__ = AsyncMock(return_value=http)
        async_cm.__aexit__ = AsyncMock(return_value=None)

        with (
            patch(
                "pycorrai.ocr.gemini_flash_ocr.ObjectStore.get_instance",
                return_value=store,
            ),
            patch.object(file, "save_attributes"),
            patch.object(file, "append_event") as append_event,
            patch(
                "pycorrai.ocr.gemini_flash_ocr.publish_file_status"
            ) as publish,
            patch(
                "pycorrai.ocr.gemini_flash_ocr._prepare_upload",
                return_value=("/tmp/fake.jpg", "f1.jpg"),
            ),
            patch(
                "pycorrai.ocr.gemini_flash_ocr.httpx.AsyncClient",
                return_value=async_cm,
            ),
            patch(
                "pycorrai.ocr.gemini_flash_ocr.GeminiFlashClient.add_file"
            ),
        ):
            GeminiFlashOcr(client_factory=lambda: client).gemini_flash_ocr([file])

        self.assertEqual(file.status, STATUS_ERROR)
        self.assertIn(EVENT_FAILED, [c.args[0] for c in append_event.call_args_list])
        self.assertEqual(publish.call_args_list[-1].kwargs["loading"], False)

    def test_sets_transcribing_before_request(self) -> None:
        file = _file()
        store = MagicMock()
        store.exists.return_value = False
        store.download_to_temp.side_effect = Exception("download failed")

        with (
            patch(
                "pycorrai.ocr.gemini_flash_ocr.ObjectStore.get_instance",
                return_value=store,
            ),
            patch.object(file, "save_attributes"),
            patch.object(file, "append_event"),
            patch("pycorrai.ocr.gemini_flash_ocr.publish_file_status") as publish,
        ):
            GeminiFlashOcr().gemini_flash_ocr([file])

        self.assertEqual(publish.call_args_list[0].kwargs["loading"], True)
        self.assertEqual(file.status, STATUS_ERROR)

    def test_module_function_delegates(self) -> None:
        with patch.object(GeminiFlashOcr, "gemini_flash_ocr") as method:
            gemini_flash_ocr([], force_re_ocr=True)
            method.assert_called_once()
            self.assertTrue(method.call_args.kwargs["force_re_ocr"])

    def test_prepare_upload_converts_png(self) -> None:
        from pycorrai.ocr.gemini_flash_ocr import _prepare_upload, _unlink_quiet

        import tempfile

        with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
            tmp.write(_png_bytes())
            src = tmp.name
        try:
            path, name = _prepare_upload(src, "page.png")
            self.assertTrue(name.endswith(".jpg"))
            self.assertNotEqual(path, src)
            with Image.open(path) as img:
                self.assertEqual(img.format, "JPEG")
            _unlink_quiet(path)
        finally:
            _unlink_quiet(src)


if __name__ == "__main__":
    unittest.main()
