"""Unit tests for the OCR Redis consumer."""

from __future__ import annotations

import io
import json
import unittest
from unittest.mock import MagicMock, patch

from pycorrai.consumer import (
    BRPOP_TIMEOUT,
    SOCKET_TIMEOUT,
    ObjectStore,
    attributes_key,
    event_body,
    event_key,
    file_id_from_path,
    file_prefix,
    format_ocr_json,
    make_redis_client,
    ocr_json_key,
    parse_ticket,
    treat,
)


CONTENT_PATH = "schools/s1/teachers/t1/exams/e1/files/f1/content"


class ParseTicketTest(unittest.TestCase):
    def test_json_path(self) -> None:
        self.assertEqual(
            parse_ticket('{"path": "schools/a/files/b/content"}'),
            {"path": "schools/a/files/b/content", "lang": "fr"},
        )

    def test_json_path_with_lang(self) -> None:
        self.assertEqual(
            parse_ticket('{"path": "x/content", "lang": "en"}'),
            {"path": "x/content", "lang": "en"},
        )

    def test_bare_path(self) -> None:
        self.assertEqual(
            parse_ticket(CONTENT_PATH),
            {"path": CONTENT_PATH, "lang": "fr"},
        )

    def test_bytes_and_leading_slash(self) -> None:
        self.assertEqual(
            parse_ticket(b"/schools/a/content"),
            {"path": "schools/a/content", "lang": "fr"},
        )

    def test_empty_rejected(self) -> None:
        with self.assertRaises(ValueError):
            parse_ticket("   ")

    def test_json_without_path_rejected(self) -> None:
        with self.assertRaises(ValueError):
            parse_ticket('{"lang": "fr"}')


class KeyDerivationTest(unittest.TestCase):
    def test_file_prefix(self) -> None:
        self.assertEqual(
            file_prefix(CONTENT_PATH),
            "schools/s1/teachers/t1/exams/e1/files/f1/",
        )

    def test_attributes_key(self) -> None:
        self.assertEqual(
            attributes_key(CONTENT_PATH),
            "schools/s1/teachers/t1/exams/e1/files/f1/attributes.json",
        )

    def test_ocr_json_key(self) -> None:
        self.assertEqual(
            ocr_json_key(CONTENT_PATH),
            "schools/s1/teachers/t1/exams/e1/files/f1/ocr_result.json",
        )

    def test_event_key_shape(self) -> None:
        key = event_key(CONTENT_PATH, timestamp=1700000000)
        prefix = "schools/s1/teachers/t1/exams/e1/files/f1/events/"
        self.assertTrue(key.startswith(prefix))
        self.assertTrue(key.endswith(".json"))
        event_id = key[len(prefix) : -len(".json")]
        stamp, sep, suffix = event_id.partition("-")
        self.assertEqual(stamp, "1700000000")
        self.assertEqual(sep, "-")
        self.assertEqual(len(suffix), 8)
        int(suffix, 16)

    def test_file_id_from_path(self) -> None:
        self.assertEqual(file_id_from_path(CONTENT_PATH), "f1")

    def test_file_id_missing_files_segment(self) -> None:
        with self.assertRaises(ValueError):
            file_id_from_path("schools/s1/content")


class FormatAndEventTest(unittest.TestCase):
    def test_format_ocr_json_pretty(self) -> None:
        words = [{"text": "café", "page": 0, "box": [1, 2, 3, 4]}]
        body = format_ocr_json(words)
        self.assertTrue(body.endswith("\n"))
        self.assertIn('"text": "café"', body)
        self.assertIn("\n  ", body)
        self.assertEqual(json.loads(body), words)

    def test_event_body(self) -> None:
        body = event_body("OCR started", timestamp=42)
        self.assertEqual(
            body,
            {"timestamp": 42, "name": "OCR started", "schema": 1},
        )


class TreatTest(unittest.TestCase):
    def test_treat_writes_events_ocr_and_enqueues(self) -> None:
        client = MagicMock()
        attrs = {"name": "copy.png", "status": "loaded", "schema": 1}
        attrs_body = json.dumps(attrs).encode("utf-8")
        client.get_object.side_effect = [
            {"Body": io.BytesIO(attrs_body)},
            {"Body": io.BytesIO(b"\xff\xd8\xfffakejpeg")},
            {"Body": io.BytesIO(attrs_body)},
            {"Body": io.BytesIO(attrs_body)},
        ]
        store = ObjectStore(client=client, bucket="corrai")
        redis = MagicMock()
        words = [{"text": "hi", "page": 0, "box": [0, 0, 1, 1]}]

        with patch("pycorrai.consumer.event_key") as mock_event_key:
            mock_event_key.side_effect = [
                "schools/s1/teachers/t1/exams/e1/files/f1/events/1-aaaaaaaa.json",
                "schools/s1/teachers/t1/exams/e1/files/f1/events/2-bbbbbbbb.json",
            ]
            treat(
                CONTENT_PATH,
                "fr",
                store,
                redis,
                recognize=lambda data, lang: words,
            )

        put_keys = [call.kwargs["Key"] for call in client.put_object.call_args_list]
        self.assertEqual(
            put_keys,
            [
                "schools/s1/teachers/t1/exams/e1/files/f1/events/1-aaaaaaaa.json",
                "schools/s1/teachers/t1/exams/e1/files/f1/ocr_result.json",
                "schools/s1/teachers/t1/exams/e1/files/f1/events/2-bbbbbbbb.json",
                "schools/s1/teachers/t1/exams/e1/files/f1/attributes.json",
            ],
        )

        started = json.loads(client.put_object.call_args_list[0].kwargs["Body"])
        self.assertEqual(started["name"], "OCR started")
        self.assertEqual(started["schema"], 1)

        ocr_body = client.put_object.call_args_list[1].kwargs["Body"]
        if isinstance(ocr_body, bytes):
            ocr_body = ocr_body.decode("utf-8")
        self.assertEqual(json.loads(ocr_body), words)
        self.assertEqual(
            client.put_object.call_args_list[1].kwargs["ContentType"],
            "application/json",
        )

        ended = json.loads(client.put_object.call_args_list[2].kwargs["Body"])
        self.assertEqual(ended["name"], "OCR ended")

        updated_attrs = json.loads(client.put_object.call_args_list[3].kwargs["Body"])
        self.assertEqual(updated_attrs["status"], "ocr_done")
        self.assertEqual(updated_attrs["name"], "copy.png")
        self.assertEqual(updated_attrs["schema"], 1)

        redis.lpush.assert_called_once_with(
            "corrai:files",
            '{"file_id":"f1"}',
        )

    def test_treat_skips_php_enqueue_when_attributes_missing(self) -> None:
        client = MagicMock()
        client.get_object.side_effect = Exception("NoSuchKey")
        store = ObjectStore(client=client, bucket="corrai")
        redis = MagicMock()

        with self.assertRaises(Exception):
            treat(
                CONTENT_PATH,
                "fr",
                store,
                redis,
                recognize=lambda data, lang: [],
            )

        client.put_object.assert_not_called()
        redis.lpush.assert_not_called()


class RedisClientTest(unittest.TestCase):
    def test_socket_timeout_exceeds_brpop(self) -> None:
        self.assertGreater(SOCKET_TIMEOUT, BRPOP_TIMEOUT)

    def test_make_redis_client_sets_timeouts(self) -> None:
        with patch("pycorrai.consumer.Redis") as redis_cls:
            make_redis_client()
        kwargs = redis_cls.call_args.kwargs
        self.assertGreater(kwargs["socket_timeout"], BRPOP_TIMEOUT)
        self.assertEqual(kwargs["socket_connect_timeout"], 5)


class EngineCacheTest(unittest.TestCase):
    def test_get_engine_reuses_instance_per_lang(self) -> None:
        import pycorrai.cli as cli

        fake = MagicMock(name="engine")
        with patch.dict(cli._engines, {}, clear=True), patch(
            "paddleocr.PaddleOCR", return_value=fake
        ) as ctor:
            first = cli.get_engine("fr")
            second = cli.get_engine("fr")
            self.assertIs(first, second)
            self.assertIs(first, fake)
            ctor.assert_called_once()


if __name__ == "__main__":
    unittest.main()
