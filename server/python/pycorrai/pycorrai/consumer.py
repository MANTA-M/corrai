"""Redis consumer that OCRs assessment files from S3 and hands them to PHP."""

from __future__ import annotations

import json
import logging
import os
import secrets
import sys
import time
from typing import Any

import boto3
from botocore.client import Config
from redis import Redis
from redis.exceptions import TimeoutError as RedisTimeoutError

from pycorrai.cli import get_engine, recognize_bytes

logger = logging.getLogger(__name__)

OCR_LIST_KEY = "corrai:ocr"
PHP_LIST_KEY = "corrai:files"
SCHEMA = 1
DEFAULT_LANG = "fr"
BRPOP_TIMEOUT = 5
# redis-py 8 defaults socket_timeout to 5s. That races an equal BRPOP and
# raises TimeoutError before Redis can return nil. Keep the read deadline
# strictly longer than the block, with room for the reply to arrive.
SOCKET_CONNECT_TIMEOUT = 5
SOCKET_TIMEOUT = BRPOP_TIMEOUT + 10


def env(key: str, default: str = "") -> str:
    value = os.environ.get(key)
    if value is None or value == "":
        return default
    return value


def parse_ticket(raw: str | bytes) -> dict[str, str]:
    """Decode a queue ticket into path, language, and optional follow-up task.

    Accepts JSON ``{"path": "..."}`` (optional ``lang`` and ``after_task``) or a
    bare path string. ``operation`` is ignored.
    """
    if isinstance(raw, bytes):
        raw = raw.decode("utf-8")
    text = raw.strip()
    if not text:
        raise ValueError("Empty OCR ticket")

    path: str
    lang = DEFAULT_LANG
    after_task = ""

    if text.startswith("{"):
        decoded = json.loads(text)
        if not isinstance(decoded, dict):
            raise ValueError(f"Invalid OCR ticket JSON: {text}")
        ticket_path = decoded.get("path")
        if not isinstance(ticket_path, str) or ticket_path.strip() == "":
            raise ValueError(f"OCR ticket missing path: {text}")
        path = ticket_path.strip()
        ticket_lang = decoded.get("lang")
        if isinstance(ticket_lang, str) and ticket_lang.strip() != "":
            lang = ticket_lang.strip()
        ticket_after = decoded.get("after_task")
        if isinstance(ticket_after, str):
            after_task = ticket_after.strip()
    else:
        path = text

    path = path.lstrip("/")
    if path == "":
        raise ValueError("OCR ticket path is empty")

    return {"path": path, "lang": lang, "after_task": after_task}


def file_prefix(content_path: str) -> str:
    """Parent prefix of a content key (trailing slash)."""
    path = content_path.rstrip("/")
    parent, sep, _name = path.rpartition("/")
    if sep == "":
        raise ValueError(f"Cannot derive file prefix from path: {content_path}")
    return parent + "/"


def attributes_key(content_path: str) -> str:
    return file_prefix(content_path) + "attributes.json"


def event_key(content_path: str, timestamp: int | None = None) -> str:
    """Build an events/<unix>-<8 hex>.json key under the file prefix."""
    ts = int(time.time()) if timestamp is None else timestamp
    event_id = f"{ts}-{secrets.token_hex(4)}"
    return file_prefix(content_path) + f"events/{event_id}.json"


def ocr_json_key(content_path: str) -> str:
    """Sibling ``ocr_result.json`` next to the content object."""
    return file_prefix(content_path) + "ocr_result.json"


def file_id_from_path(content_path: str) -> str:
    """Extract the file id, the directory that contains the ``content`` object."""
    parts = [part for part in content_path.strip("/").split("/") if part != ""]
    if len(parts) < 2 or parts[-1] != "content":
        raise ValueError(f"Path is not a content object: {content_path}")
    file_id = parts[-2]
    if file_id == "":
        raise ValueError(f"Empty file id in path: {content_path}")
    return file_id


def format_ocr_json(words: list[dict[str, Any]]) -> str:
    """Pretty-print OCR words the same way PHP pipelines do."""
    return json.dumps(words, indent=2, ensure_ascii=False) + "\n"


def event_body(name: str, timestamp: int | None = None) -> dict[str, Any]:
    ts = int(time.time()) if timestamp is None else timestamp
    return {
        "timestamp": ts,
        "name": name,
        "schema": SCHEMA,
    }


def make_s3_client():
    endpoint = env("S3_ENDPOINT", "http://seaweedfs:8333")
    region = env("S3_REGION", "us-east-1")
    access_key = env("S3_ACCESS_KEY")
    secret_key = env("S3_SECRET_KEY")
    if access_key == "" or secret_key == "":
        raise RuntimeError("S3_ACCESS_KEY and S3_SECRET_KEY must be configured")

    # Newer botocore defaults to always sending checksums; SeaweedFS rejects them.
    # Older botocore has no such knobs, so fall back to path-style only.
    try:
        config = Config(
            s3={"addressing_style": "path"},
            request_checksum_calculation="when_required",
            response_checksum_validation="when_required",
        )
    except TypeError:
        config = Config(s3={"addressing_style": "path"})

    return boto3.client(
        "s3",
        endpoint_url=endpoint,
        region_name=region,
        aws_access_key_id=access_key,
        aws_secret_access_key=secret_key,
        config=config,
    )


def make_redis_client() -> Redis:
    host = env("REDIS_HOST", "127.0.0.1")
    port = int(env("REDIS_PORT", "6379"))
    return Redis(
        host=host,
        port=port,
        decode_responses=True,
        socket_connect_timeout=SOCKET_CONNECT_TIMEOUT,
        socket_timeout=SOCKET_TIMEOUT,
    )


class ObjectStore:
    """Thin S3 wrapper matching PHP ObjectStore behaviour used by the consumer."""

    def __init__(self, client=None, bucket: str | None = None):
        self.client = client if client is not None else make_s3_client()
        self.bucket = bucket if bucket is not None else env("S3_BUCKET", "corrai")

    def get_bytes(self, key: str) -> bytes:
        response = self.client.get_object(Bucket=self.bucket, Key=key)
        body = response["Body"].read()
        return body if isinstance(body, bytes) else bytes(body)

    def get_json(self, key: str) -> dict[str, Any]:
        raw = self.get_bytes(key)
        data = json.loads(raw.decode("utf-8"))
        if not isinstance(data, dict):
            raise ValueError(f"Invalid JSON object at {key}")
        return data

    def put_bytes(self, key: str, body: bytes | str, content_type: str) -> None:
        if isinstance(body, str):
            body = body.encode("utf-8")
        self.client.put_object(
            Bucket=self.bucket,
            Key=key,
            Body=body,
            ContentType=content_type,
        )

    def put_json(self, key: str, data: dict[str, Any]) -> None:
        payload = dict(data)
        if "schema" not in payload:
            payload["schema"] = SCHEMA
        body = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
        self.put_bytes(key, body, "application/json")


def php_task_ticket(content_path: str, after_task: str) -> str:
    """PHP queue ticket that runs ``after_task`` on the same content path."""
    return json.dumps(
        {"path": content_path, "task": after_task},
        ensure_ascii=False,
        separators=(",", ":"),
    )


def treat(
    content_path: str,
    lang: str,
    store: ObjectStore,
    redis: Redis,
    recognize=recognize_bytes,
    after_task: str = "",
) -> None:
    """OCR one file path and, when set, enqueue ``after_task`` for PHP."""
    logger.info("OCR starting for %s lang=%s", content_path, lang)
    attr_key = attributes_key(content_path)
    store.get_json(attr_key)
    try:
        store.put_json(event_key(content_path), event_body("OCR started"))

        content = store.get_bytes(content_path)
        if not content:
            raise ValueError(f"Empty content at {content_path}")

        words = recognize(content, lang)
        store.put_bytes(
            ocr_json_key(content_path),
            format_ocr_json(words),
            "application/json",
        )

        store.get_json(attr_key)
        store.put_json(event_key(content_path), event_body("OCR ended"))

        attrs = store.get_json(attr_key)
        attrs["status"] = "ocr_done"
        store.put_json(attr_key, attrs)

        follow_up = after_task.strip()
        if follow_up != "":
            redis.lpush(PHP_LIST_KEY, php_task_ticket(content_path, follow_up))
            logger.info(
                "OCR finished for %s, enqueued task=%s",
                content_path,
                follow_up,
            )
        else:
            logger.info("OCR finished for %s, no follow-up task", content_path)
    except Exception:
        try:
            attrs = store.get_json(attr_key)
            attrs["status"] = "error"
            store.put_json(attr_key, attrs)
            store.put_json(event_key(content_path), event_body("OCR failed"))
        except Exception:
            logger.exception("Failed to record OCR failure for %s", content_path)
        raise


def run_forever(
    store: ObjectStore | None = None,
    redis: Redis | None = None,
    recognize=recognize_bytes,
    preload_lang: str | None = DEFAULT_LANG,
) -> None:
    store = store if store is not None else ObjectStore()
    redis = redis if redis is not None else make_redis_client()

    # Load PaddleOCR models once into this process so tickets reuse them.
    if preload_lang is not None and recognize is recognize_bytes:
        logger.info("Loading OCR models for lang=%s", preload_lang)
        get_engine(preload_lang)
        logger.info("OCR models ready for lang=%s", preload_lang)

    logger.info("OCR consumer started, waiting on %s", OCR_LIST_KEY)
    while True:
        try:
            result = redis.brpop(OCR_LIST_KEY, timeout=BRPOP_TIMEOUT)
        except RedisTimeoutError:
            logger.warning(
                "Redis read timed out while waiting on %s; retrying",
                OCR_LIST_KEY,
            )
            continue
        except Exception:
            logger.exception("OCR consumer error")
            continue

        if result is None:
            continue
        try:
            _key, raw = result
            ticket = parse_ticket(raw)
            logger.info("Treating OCR ticket %s", ticket["path"])
            treat(
                ticket["path"],
                ticket["lang"],
                store,
                redis,
                recognize=recognize,
                after_task=ticket["after_task"],
            )
        except Exception:
            logger.exception("OCR consumer error")


def main(argv: list[str] | None = None) -> int:
    del argv  # CLI takes no arguments; reserved for future flags.
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        stream=sys.stderr,
    )
    run_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
