"""Google Vision OCR for a list of ``InputFile`` (async, like PHP AssTask1Transcript)."""

from __future__ import annotations

import asyncio
import json
import logging
import os
import ssl
import tempfile
import threading
import time
import urllib.error
import urllib.request
from typing import Any, Sequence
from urllib.parse import quote

import httpx

from pycorrai.clients.google.vision import Vision
from pycorrai.config import env
from pycorrai.model.input_file import InputFile
from pycorrai.model.ocr_result import OCRResult
from pycorrai.store.object_store import ObjectStore

logger = logging.getLogger(__name__)

OCR_LANG = "fr"

# Status keys / FR labels aligned with PHP InputFile::STATUS_LABELS.
STATUS_TRANSCRIBING = "transcribing"
STATUS_TRANSCRIBED = "transcribed"
STATUS_ERROR = "error"

STATUS_LABELS = {
    STATUS_TRANSCRIBING: "Transcription en cours",
    STATUS_TRANSCRIBED: "Transcrit",
    STATUS_ERROR: "Erreur",
}

EVENT_START = "Début transcription"
EVENT_FAILED = "OCR failed"

# Document text detection list price (USD per image / page unit).
COST_PER_UNIT_USD = 0.0015


def google_vision_ocr(
    files: Sequence[InputFile],
    force_re_ocr: bool = False,
    *,
    vision_client: Vision | None = None,
) -> None:
    """Run Google Vision OCR on each file.

    Blocks until every launched request has returned or failed.
    When ``force_re_ocr`` is false, files that already have ``ocr_result.json``
    are skipped. Statuses and Nchan ``loading`` follow the PHP pipeline.
    """
    GoogleVisionOcr(vision_client=vision_client).google_vision_ocr(
        files, force_re_ocr=force_re_ocr
    )


class GoogleVisionOcr:
    def __init__(self, vision_client: Vision | None = None) -> None:
        self._vision = vision_client
        # Vision.set_file / process mutate instance state; serialize document OCR.
        self._document_lock = threading.Lock()

    def google_vision_ocr(
        self, files: Sequence[InputFile], force_re_ocr: bool = False
    ) -> None:
        """Mark files, fire OCR requests, wait for all responses, then return."""
        store = ObjectStore.get_instance()
        client = self._vision or Vision()
        jobs: list[dict[str, Any]] = []

        for file in files:
            if file.id is None or file.id == "":
                logger.error("[google_vision_ocr] Skipping file without id")
                continue

            ocr_key = file.ocr_result_key()
            if not force_re_ocr and store.exists(ocr_key):
                continue

            if file.size > Vision.MAX_FILE_SIZE:
                self._fail_file(file, "Image file too heavy")
                continue

            started_at = time.perf_counter()
            try:
                file.status = STATUS_TRANSCRIBING
                file.save_attributes()
                self._publish_file(file, loading=True)
                file.append_event(EVENT_START)
            except Exception:
                logger.exception(
                    "[google_vision_ocr] Failed to mark file %s as transcribing",
                    file.id,
                )
                self._fail_file(file, EVENT_FAILED)
                continue

            mime = str(file.content_type or "").split(";", 1)[0].strip().lower()
            if Vision.isDocumentMime(mime):
                jobs.append(
                    {
                        "kind": "document",
                        "file": file,
                        "started_at": started_at,
                    }
                )
                continue

            try:
                content = store.get_bytes(file.content_key())
            except Exception as exc:
                logger.exception(
                    "[google_vision_ocr] Failed to load content for %s", file.id
                )
                self._fail_file(file, str(exc) or EVENT_FAILED)
                continue

            if content == b"" or len(content) > Vision.MAX_FILE_SIZE:
                self._fail_file(file, "Image file too heavy")
                continue

            jobs.append(
                {
                    "kind": "image",
                    "file": file,
                    "started_at": started_at,
                    "post_data": client.buildImagesAnnotatePostData(
                        content, OCR_LANG
                    ),
                }
            )

        if not jobs:
            return

        try:
            client.authorize()
        except Exception as exc:
            logger.exception("[google_vision_ocr] Vision authorize failed")
            for job in jobs:
                self._fail_file(job["file"], str(exc) or EVENT_FAILED)
            return

        # Blocks until every job has finished (success or error).
        asyncio.run(self._run_all_jobs(client, jobs))

    async def _run_all_jobs(
        self, client: Vision, jobs: list[dict[str, Any]]
    ) -> None:
        timeout = httpx.Timeout(
            connect=2.0,
            read=float(client.timeout) if client.timeout > 0 else 180.0,
            write=60.0,
            pool=5.0,
        )
        headers = {
            "Authorization": f"Bearer {client.token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }
        verify = not client.disable_ssl_verif

        async with httpx.AsyncClient(timeout=timeout, verify=verify) as http:
            tasks = [self._run_job(client, http, headers, job) for job in jobs]
            await asyncio.gather(*tasks)

    async def _run_job(
        self,
        client: Vision,
        http: httpx.AsyncClient,
        headers: dict[str, str],
        job: dict[str, Any],
    ) -> None:
        """Complete one file OCR; always settles (success or error)."""
        file: InputFile = job["file"]
        started_at: float = job["started_at"]
        try:
            if job["kind"] == "document":
                result, units = await asyncio.to_thread(
                    self._process_document_sync, file, client
                )
            else:
                response = await http.post(
                    Vision.IMAGES_ANNOTATE_URL,
                    headers=headers,
                    content=json.dumps(
                        job["post_data"], ensure_ascii=False
                    ).encode("utf-8"),
                )
                client.last_response_code = response.status_code
                body = response.text
                if response.status_code < 200 or response.status_code > 299:
                    raise Exception(
                        body or f"OCR request failed ({response.status_code})"
                    )
                if not body:
                    raise Exception("Empty OCR response")
                output = client.outputFromImagesAnnotateBody(body)
                result = OCRResult.from_google(output)
                units = 1
            self._finish_success(file, result, started_at, units=units)
        except Exception as exc:
            logger.exception(
                "[google_vision_ocr] OCR failed for file %s", file.id
            )
            self._fail_file(file, str(exc) or EVENT_FAILED)

    def _process_document_sync(
        self, file: InputFile, client: Vision
    ) -> tuple[OCRResult, int]:
        store = ObjectStore.get_instance()
        copy_path: str | None = None
        try:
            with tempfile.NamedTemporaryFile(delete=False) as tmp:
                copy_path = tmp.name
                tmp.write(store.get_bytes(file.content_key()))
            with self._document_lock:
                client.set_file(copy_path, file.name, file)
                client.set_language(OCR_LANG)
                output = client.process()
            result = OCRResult.from_google(output)
            pages = max(
                1, len({w.page for w in result.words}) if result.words else 1
            )
            return result, pages
        finally:
            if copy_path is not None:
                try:
                    if os.path.isfile(copy_path):
                        os.unlink(copy_path)
                except OSError:
                    pass

    def _finish_success(
        self,
        file: InputFile,
        result: OCRResult,
        started_at: float,
        *,
        units: int,
    ) -> None:
        store = ObjectStore.get_instance()
        ocr_key = file.ocr_result_key()
        try:
            if store.exists(ocr_key):
                store.delete(ocr_key)
            store.put_contents(ocr_key, result.to_json(True), "application/json")

            duration_ms = max(0, round((time.perf_counter() - started_at) * 1000))
            cost = COST_PER_UNIT_USD * max(1, units)
            file.status = STATUS_TRANSCRIBED
            file.save_attributes()
            self._publish_file(file, loading=False)
            file.append_event(
                f"Transcription ({duration_ms} ms, {cost:.4f} USD)"
            )
        except Exception:
            logger.exception(
                "[google_vision_ocr] Failed to persist OCR for file %s", file.id
            )
            self._fail_file(file, EVENT_FAILED)

    def _fail_file(self, file: InputFile, event_name: str) -> None:
        try:
            name = (
                event_name
                if event_name in ("Image file too heavy", EVENT_FAILED)
                else EVENT_FAILED
            )
            file.append_event(name)
            file.status = STATUS_ERROR
            file.save_attributes()
        except Exception:
            logger.exception(
                "[google_vision_ocr] Failed to mark file %s as error", file.id
            )
        try:
            self._publish_file(file, loading=False)
        except Exception:
            logger.exception(
                "[google_vision_ocr] Failed to publish error status for %s",
                file.id,
            )

    def _publish_file(self, file: InputFile, *, loading: bool) -> None:
        publish_file_status(file, loading=loading)


def publish_file_status(file: InputFile, *, loading: bool) -> None:
    """POST file status + loading flag to the assessment (and student) Nchan channels."""
    if file.id is None or file.id == "" or file.assessment_id == "":
        return

    status = file.status
    file_payload: dict[str, Any] = {
        "id": file.id,
        "status": status,
        "loading": loading,
    }
    label = STATUS_LABELS.get(status)
    if label is not None:
        file_payload["status_label"] = label

    body_obj: dict[str, Any] = {"scope": "assessment", "file": file_payload}
    _nchan_publish(f"assessment:{file.assessment_id}", body_obj)

    student_id = (file.student_id() or "").strip()
    if student_id != "":
        student_body = {
            "scope": "student",
            "file": file_payload,
            "student": {"id": student_id, "loading": loading},
        }
        _nchan_publish(f"student:{student_id}", student_body)


def _nchan_publish(channel: str, payload: dict[str, Any]) -> None:
    body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode(
        "utf-8"
    )
    base = env(
        "NCHAN_PUBLISH_URL", "https://127.0.0.1/corrai_test/internal/pub"
    ).rstrip("/")
    request = urllib.request.Request(
        f"{base}/{quote(channel, safe='')}",
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Host": env("NCHAN_PUBLISH_HOST", "mantam.eu"),
        },
    )
    try:
        with urllib.request.urlopen(
            request,
            timeout=2,
            context=ssl._create_unverified_context(),
        ) as response:
            if response.status < 200 or response.status >= 300:
                logger.error(
                    "Nchan publish %s returned HTTP %s", channel, response.status
                )
    except urllib.error.HTTPError as exc:
        logger.error("Nchan publish %s returned HTTP %s", channel, exc.code)
    except Exception:
        logger.exception("Failed to publish Nchan event on %s", channel)
