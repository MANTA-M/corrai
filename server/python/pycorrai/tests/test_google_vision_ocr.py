"""Unit tests for google_vision_ocr."""

from __future__ import annotations

import asyncio
import json
import unittest
from unittest.mock import AsyncMock, MagicMock, patch

from pycorrai.clients.google.vision import Vision
from pycorrai.model.submission_file import SubmissionFile
from pycorrai.ocr.google_vision_ocr import (
    COST_PER_UNIT_USD,
    EVENT_FAILED,
    EVENT_START,
    STATUS_ERROR,
    STATUS_TRANSCRIBED,
    GoogleVisionOcr,
    google_vision_ocr,
)


def _file(
    file_id: str = "f1",
    *,
    size: int = 100,
    content_type: str = "image/png",
    student: str | None = None,
) -> SubmissionFile:
    f = SubmissionFile()
    f.id = file_id
    f.school_id = "s1"
    f.user_id = "t1"
    f.assessment_id = "a1"
    f.name = f"{file_id}.png"
    f.size = size
    f.content_type = content_type
    f.status = "stored"
    f.student = student
    return f


GOOGLE_OUTPUT = {
    "text": "Une makine",
    "bounding_boxes": [
        {
            "text": "Une",
            "left": 0.04,
            "top": 0.04,
            "width": 0.08,
            "height": 0.02,
            "page": 0,
        }
    ],
    "usage": None,
}


class GoogleVisionOcrTest(unittest.TestCase):
    def test_skips_when_ocr_result_exists_without_force(self) -> None:
        file = _file()
        store = MagicMock()
        store.exists.return_value = True
        vision = MagicMock(spec=Vision)

        with patch(
            "pycorrai.ocr.google_vision_ocr.ObjectStore.get_instance",
            return_value=store,
        ):
            GoogleVisionOcr(vision_client=vision).google_vision_ocr([file])

        vision.authorize.assert_not_called()
        self.assertEqual(file.status, "stored")

    def test_force_re_ocr_processes_existing_result(self) -> None:
        file = _file()
        store = MagicMock()
        store.exists.side_effect = lambda key: key.endswith("ocr_result.json")
        store.get_bytes.return_value = b"fake-image-bytes"
        vision = MagicMock(spec=Vision)
        vision.timeout = 30
        vision.disable_ssl_verif = True
        vision.token = "tok"
        vision.buildImagesAnnotatePostData.return_value = {"requests": []}
        vision.outputFromImagesAnnotateBody.return_value = GOOGLE_OUTPUT

        response = MagicMock()
        response.status_code = 200
        response.text = json.dumps({"responses": [{}]})

        http = AsyncMock()
        http.post = AsyncMock(return_value=response)
        async_cm = MagicMock()
        async_cm.__aenter__ = AsyncMock(return_value=http)
        async_cm.__aexit__ = AsyncMock(return_value=None)

        with (
            patch(
                "pycorrai.ocr.google_vision_ocr.ObjectStore.get_instance",
                return_value=store,
            ),
            patch.object(file, "save_attributes"),
            patch.object(file, "append_event") as append_event,
            patch(
                "pycorrai.ocr.google_vision_ocr.publish_file_status"
            ) as publish,
            patch(
                "pycorrai.ocr.google_vision_ocr.httpx.AsyncClient",
                return_value=async_cm,
            ),
        ):
            GoogleVisionOcr(vision_client=vision).google_vision_ocr(
                [file], force_re_ocr=True
            )

        self.assertEqual(file.status, STATUS_TRANSCRIBED)
        store.delete.assert_called()
        store.put_contents.assert_called()
        event_names = [c.args[0] for c in append_event.call_args_list]
        self.assertEqual(event_names[0], EVENT_START)
        self.assertTrue(event_names[-1].startswith("Transcription ("))
        self.assertIn(f"{COST_PER_UNIT_USD:.4f} USD", event_names[-1])
        self.assertEqual(publish.call_args_list[0].kwargs["loading"], True)
        self.assertEqual(publish.call_args_list[-1].kwargs["loading"], False)

    def test_error_sets_status_and_publishes_loading_false(self) -> None:
        file = _file()
        store = MagicMock()
        store.exists.return_value = False
        store.get_bytes.return_value = b"fake"
        vision = MagicMock(spec=Vision)
        vision.timeout = 30
        vision.disable_ssl_verif = True
        vision.token = "tok"
        vision.buildImagesAnnotatePostData.return_value = {"requests": []}

        response = MagicMock()
        response.status_code = 500
        response.text = "boom"

        http = AsyncMock()
        http.post = AsyncMock(return_value=response)
        async_cm = MagicMock()
        async_cm.__aenter__ = AsyncMock(return_value=http)
        async_cm.__aexit__ = AsyncMock(return_value=None)

        with (
            patch(
                "pycorrai.ocr.google_vision_ocr.ObjectStore.get_instance",
                return_value=store,
            ),
            patch.object(file, "save_attributes"),
            patch.object(file, "append_event") as append_event,
            patch(
                "pycorrai.ocr.google_vision_ocr.publish_file_status"
            ) as publish,
            patch(
                "pycorrai.ocr.google_vision_ocr.httpx.AsyncClient",
                return_value=async_cm,
            ),
        ):
            GoogleVisionOcr(vision_client=vision).google_vision_ocr([file])

        self.assertEqual(file.status, STATUS_ERROR)
        self.assertIn(EVENT_FAILED, [c.args[0] for c in append_event.call_args_list])
        self.assertEqual(publish.call_args_list[-1].kwargs["loading"], False)

    def test_module_function_delegates(self) -> None:
        with patch.object(GoogleVisionOcr, "google_vision_ocr") as method:
            google_vision_ocr([], force_re_ocr=True)
            method.assert_called_once()
            self.assertTrue(method.call_args.kwargs["force_re_ocr"])

    def test_too_heavy_before_request(self) -> None:
        file = _file(size=Vision.MAX_FILE_SIZE + 1)
        store = MagicMock()
        store.exists.return_value = False
        vision = MagicMock(spec=Vision)

        with (
            patch(
                "pycorrai.ocr.google_vision_ocr.ObjectStore.get_instance",
                return_value=store,
            ),
            patch.object(file, "save_attributes"),
            patch.object(file, "append_event") as append_event,
            patch("pycorrai.ocr.google_vision_ocr.publish_file_status"),
        ):
            GoogleVisionOcr(vision_client=vision).google_vision_ocr([file])

        self.assertEqual(file.status, STATUS_ERROR)
        self.assertEqual(
            append_event.call_args_list[0].args[0], "Image file too heavy"
        )
        vision.authorize.assert_not_called()

    def test_waits_for_all_requests_before_returning(self) -> None:
        files = [_file("f1"), _file("f2"), _file("f3")]
        store = MagicMock()
        store.exists.return_value = False
        store.get_bytes.return_value = b"fake"
        vision = MagicMock(spec=Vision)
        vision.timeout = 30
        vision.disable_ssl_verif = True
        vision.token = "tok"
        vision.buildImagesAnnotatePostData.return_value = {"requests": []}
        vision.outputFromImagesAnnotateBody.return_value = GOOGLE_OUTPUT

        started = 0
        finished = 0
        lock = asyncio.Lock()

        async def _post(*_a, **_k):
            nonlocal started, finished
            async with lock:
                started += 1
                n = started
            # Stagger completions; gather must still wait for the slowest.
            await asyncio.sleep(0.06 if n == 1 else 0.01)
            async with lock:
                finished += 1
            response = MagicMock()
            response.status_code = 200
            response.text = json.dumps({"responses": [{}]})
            return response

        http = AsyncMock()
        http.post = _post
        async_cm = MagicMock()
        async_cm.__aenter__ = AsyncMock(return_value=http)
        async_cm.__aexit__ = AsyncMock(return_value=None)

        with (
            patch(
                "pycorrai.ocr.google_vision_ocr.ObjectStore.get_instance",
                return_value=store,
            ),
            patch(
                "pycorrai.ocr.google_vision_ocr.publish_file_status",
            ),
            patch(
                "pycorrai.ocr.google_vision_ocr.httpx.AsyncClient",
                return_value=async_cm,
            ),
        ):
            for f in files:
                f.save_attributes = MagicMock()
                f.append_event = MagicMock()
            GoogleVisionOcr(vision_client=vision).google_vision_ocr(files)
            # After return, every request must have completed.
            self.assertEqual(finished, 3)
            self.assertEqual(started, 3)

        for f in files:
            self.assertEqual(f.status, STATUS_TRANSCRIBED)


if __name__ == "__main__":
    unittest.main()
