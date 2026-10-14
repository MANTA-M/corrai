"""Gemini Flash OCR for a list of ``InputFile`` (async, per-file).

Statuses, Nchan ``loading``, and event names follow ``google_vision_ocr``.
Prompt / JSON schema mirror PHP ``AssTaskGeminiOCR::transcriptManuscript``.
"""

from __future__ import annotations

import asyncio
import io
import json
import logging
import os
import tempfile
import time
from pathlib import Path
from typing import Any, Callable, Sequence

import httpx
from PIL import Image

from pycorrai.clients.openrouter.gemini_flash_client import GeminiFlashClient
from pycorrai.image.resize import ImageRedimensioner, mime_type_for_filename
from pycorrai.model.input_file import InputFile
from pycorrai.model.ocr_result import OCRResult
from pycorrai.ocr.google_vision_ocr import (
    EVENT_FAILED,
    EVENT_START,
    STATUS_ERROR,
    STATUS_TRANSCRIBED,
    STATUS_TRANSCRIBING,
    publish_file_status,
)
from pycorrai.store.object_store import ObjectStore

logger = logging.getLogger(__name__)

SYSTEM_CONTENT = (
    "You transcript handwritten exam papers. "
    'Respond only with a JSON object {"text": string, "caligraphy_quality": number}.'
)

JSON_SCHEMA: dict[str, Any] = {
    "type": "object",
    "properties": {
        "text": {
            "type": "string",
            "description": (
                "Full transcript of all manuscript text from the images, in order."
            ),
        },
        "caligraphy_quality": {
            "type": "number",
            "description": "Handwriting / calligraphy quality from 0 to 1.",
        },
    },
    "required": ["text", "caligraphy_quality"],
    "additionalProperties": False,
}

USER_PROMPT = "Transcript all manuscript text"

# Longest side when converting images to JPEG (same as PHP FirstPageImage).
_JPEG_MAX_DIMENSION = 1568
_JPEG_QUALITY = 82

ClientFactory = Callable[[], GeminiFlashClient]


def gemini_flash_ocr(
    files: Sequence[InputFile],
    force_re_ocr: bool = False,
    *,
    client_factory: ClientFactory | None = None,
) -> None:
    """Run Gemini Flash OCR on each file in parallel.

    When ``force_re_ocr`` is false, files that already have ``ocr_result.json``
    are skipped. Statuses and Nchan ``loading`` follow the PHP pipeline.
    """
    GeminiFlashOcr(client_factory=client_factory).gemini_flash_ocr(
        files, force_re_ocr=force_re_ocr
    )


class GeminiFlashOcr:
    def __init__(self, client_factory: ClientFactory | None = None) -> None:
        self._client_factory = client_factory or GeminiFlashClient

    def gemini_flash_ocr(
        self, files: Sequence[InputFile], force_re_ocr: bool = False
    ) -> None:
        store = ObjectStore.get_instance()
        pending: list[dict[str, Any]] = []

        for file in files:
            if file.id is None or file.id == "":
                logger.error("[gemini_flash_ocr] Skipping file without id")
                continue

            ocr_key = file.ocr_result_key()
            if not force_re_ocr and store.exists(ocr_key):
                continue

            started_at = time.perf_counter()
            try:
                file.status = STATUS_TRANSCRIBING
                file.save_attributes()
                self._publish_file(file, loading=True)
                file.append_event(EVENT_START)
            except Exception:
                logger.exception(
                    "[gemini_flash_ocr] Failed to mark file %s as transcribing",
                    file.id,
                )
                self._fail_file(file, EVENT_FAILED)
                continue

            copy_path: str | None = None
            jpeg_path: str | None = None
            try:
                copy_path = store.download_to_temp(file.content_key())
                jpeg_path, upload_name = _prepare_upload(copy_path, file.name)
                client = self._build_client(jpeg_path, upload_name)
                temp_paths = [copy_path]
                if jpeg_path != copy_path:
                    temp_paths.append(jpeg_path)
                pending.append(
                    {
                        "file": file,
                        "started_at": started_at,
                        "payload": client.payload,
                        "headers": _auth_headers(client),
                        "timeout": float(client.timeout) if client.timeout > 0 else 180.0,
                        "verify": not client.disable_ssl_verif,
                        "temp_paths": temp_paths,
                    }
                )
                # Ownership of temps moves to pending; clear locals.
                copy_path = None
                jpeg_path = None
            except Exception as exc:
                logger.exception(
                    "[gemini_flash_ocr] Failed to prepare file %s", file.id
                )
                self._fail_file(file, str(exc) or EVENT_FAILED)
                for path in (copy_path, jpeg_path):
                    _unlink_quiet(path)

        if not pending:
            return

        try:
            asyncio.run(self._run_async(pending))
        finally:
            for entry in pending:
                for path in entry.get("temp_paths") or []:
                    _unlink_quiet(path)

    def _build_client(self, file_path: str, file_name: str) -> GeminiFlashClient:
        client = self._client_factory()
        client.set_system_content(SYSTEM_CONTENT)
        client.set_json_response("gemini_ocr", JSON_SCHEMA)
        client.add_text(USER_PROMPT)
        client.add_file(file_path, file_name)
        return client

    async def _run_async(self, pending: list[dict[str, Any]]) -> None:
        tasks = [self._request_one(entry) for entry in pending]
        await asyncio.gather(*tasks)

    async def _request_one(self, entry: dict[str, Any]) -> None:
        file: InputFile = entry["file"]
        started_at: float = entry["started_at"]
        timeout = httpx.Timeout(
            connect=2.0,
            read=entry["timeout"],
            write=60.0,
            pool=5.0,
        )
        try:
            async with httpx.AsyncClient(
                timeout=timeout, verify=entry["verify"]
            ) as http:
                response = await http.post(
                    GeminiFlashClient.OPENROUTER_API_URL,
                    headers=entry["headers"],
                    content=json.dumps(
                        entry["payload"], ensure_ascii=False
                    ).encode("utf-8"),
                )
            if response.status_code < 200 or response.status_code > 299:
                raise Exception(
                    response.text
                    or f"OCR request failed ({response.status_code})"
                )
            raw = response.json()
            text, cost = _parse_gemini_response(raw)
            self._finish_success(file, text, started_at, cost=cost)
        except Exception as exc:
            logger.exception(
                "[gemini_flash_ocr] OCR failed for file %s", file.id
            )
            self._fail_file(file, str(exc) or EVENT_FAILED)

    def _finish_success(
        self,
        file: InputFile,
        text: str,
        started_at: float,
        *,
        cost: float,
    ) -> None:
        store = ObjectStore.get_instance()
        ocr_key = file.ocr_result_key()
        try:
            if store.exists(ocr_key):
                data = store.get_json(ocr_key)
                if not isinstance(data, dict):
                    data = {}
                data["text"] = text
                body = json.dumps(
                    data, ensure_ascii=False, indent=2, separators=(",", ": ")
                )
                store.put_contents(ocr_key, body, "application/json")
            else:
                result = OCRResult([], text)
                store.put_contents(
                    ocr_key, result.to_json(True), "application/json"
                )

            duration_ms = max(0, round((time.perf_counter() - started_at) * 1000))
            file.status = STATUS_TRANSCRIBED
            file.save_attributes()
            self._publish_file(file, loading=False)
            file.append_event(
                f"Transcription ({duration_ms} ms, {cost:.4f} USD)"
            )
        except Exception:
            logger.exception(
                "[gemini_flash_ocr] Failed to persist OCR for file %s", file.id
            )
            self._fail_file(file, EVENT_FAILED)

    def _fail_file(self, file: InputFile, event_name: str) -> None:
        try:
            name = event_name if event_name == EVENT_FAILED else EVENT_FAILED
            file.append_event(name)
            file.status = STATUS_ERROR
            file.save_attributes()
        except Exception:
            logger.exception(
                "[gemini_flash_ocr] Failed to mark file %s as error", file.id
            )
        try:
            self._publish_file(file, loading=False)
        except Exception:
            logger.exception(
                "[gemini_flash_ocr] Failed to publish error status for %s",
                file.id,
            )

    def _publish_file(self, file: InputFile, *, loading: bool) -> None:
        publish_file_status(file, loading=loading)


def _auth_headers(client: GeminiFlashClient) -> dict[str, str]:
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }
    if client.token:
        headers["Authorization"] = f"Bearer {client.token}"
    for item in client.common_headers:
        if ":" in item:
            key, value = item.split(":", 1)
            headers[key.strip()] = value.strip()
    return headers


def _parse_gemini_response(raw: dict[str, Any]) -> tuple[str, float]:
    """Extract transcript text and OpenRouter usage cost from a chat completion."""
    try:
        content = raw["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError) as exc:
        raise Exception("Invalid Gemini OCR response") from exc

    cleaned = str(content).replace("```", "").replace("json", "").strip()
    if not cleaned:
        raise Exception("Empty Gemini OCR response")
    try:
        parsed = json.loads(cleaned)
    except json.JSONDecodeError as exc:
        raise Exception(f"Gemini OCR failed: {cleaned}") from exc

    if not isinstance(parsed, dict) or "text" not in parsed:
        detail = cleaned if isinstance(parsed, str) else "empty OCR response"
        raise Exception(f"Gemini OCR failed: {detail}")

    text = parsed["text"]
    if not isinstance(text, str):
        raise Exception("Gemini OCR text must be a string")

    cost = 0.0
    usage = raw.get("usage")
    if isinstance(usage, dict):
        raw_cost = usage.get("cost")
        if isinstance(raw_cost, (int, float)) and not isinstance(raw_cost, bool):
            cost = float(raw_cost)
        elif isinstance(raw_cost, str) and raw_cost.strip() != "":
            try:
                cost = float(raw_cost)
            except ValueError:
                cost = 0.0
    return text, cost


def _jpeg_filename(name: str) -> str:
    base = Path(name).stem
    if base == "":
        base = "page"
    return f"{base}.jpg"


def _prepare_upload(source_path: str, filename: str) -> tuple[str, str]:
    """Return ``(path, upload_name)`` suitable for ``GeminiFlashClient.add_file``.

    Images are re-encoded as JPEG (Gemini rejects webp etc.). PDFs are left as-is
    so ``add_file`` can send them as PDF data URLs.
    """
    mime = mime_type_for_filename(filename).split(";", 1)[0].strip().lower()
    data = Path(source_path).read_bytes()
    if not data:
        raise Exception("Cannot read file")

    if data.startswith(b"%PDF") or mime == "application/pdf":
        return source_path, filename

    if not mime.startswith("image/"):
        try:
            Image.open(io.BytesIO(data)).verify()
        except Exception as exc:
            raise Exception("File must be a PDF or an image") from exc

    resized = ImageRedimensioner(_JPEG_MAX_DIMENSION, data).image
    with Image.open(io.BytesIO(resized)) as img:
        rgb = img.convert("RGB") if img.mode != "RGB" else img
        fd, out_path = tempfile.mkstemp(prefix="gemini_ocr_", suffix=".jpg")
        os.close(fd)
        try:
            rgb.save(out_path, format="JPEG", quality=_JPEG_QUALITY)
        except Exception:
            _unlink_quiet(out_path)
            raise
    return out_path, _jpeg_filename(filename)


def _unlink_quiet(path: str | None) -> None:
    if path is None:
        return
    try:
        if os.path.isfile(path):
            os.unlink(path)
    except OSError:
        pass
