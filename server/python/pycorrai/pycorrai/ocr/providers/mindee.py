"""Mindee raw text OCR, mirroring PHP Mindee/MindeeOCR.php."""

from __future__ import annotations

import json
import logging
import os
import secrets
import time
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

import httpx

from pycorrai.config import ensure_env_loaded, env
from pycorrai.http.rest import LlmClient
from pycorrai.image.resize import mime_type_for_filename

logger = logging.getLogger(__name__)

try:
    from mindee import PathInput, UrlInputSource  # type: ignore
    from mindee.v2 import Client as MindeeSdkClient  # type: ignore
    from mindee.v2 import OCRParameters, OCRResponse  # type: ignore

    _MINDEE_SDK_AVAILABLE = True
except ImportError:  # pragma: no cover - optional dependency
    _MINDEE_SDK_AVAILABLE = False
    PathInput = None  # type: ignore
    UrlInputSource = None  # type: ignore
    MindeeSdkClient = None  # type: ignore
    OCRParameters = None  # type: ignore
    OCRResponse = None  # type: ignore


class MindeeOCR(LlmClient):
    """Mindee raw-text OCR with the same process() contract as Vision."""

    DEFAULT_MODEL_ID = "437173fd-6284-4051-8bc9-8e811b52c0a6"
    MAX_FILE_SIZE = 10 * 1024 * 1024  # 10 Mo (10 MB)
    LOG_RESPONSE = True
    ENQUEUE_URL = "https://api-v2.mindee.net/v2/products/ocr/enqueue"
    POLL_INITIAL_DELAY = 3.0
    POLL_INTERVAL = 1.0
    POLL_MAX_ATTEMPTS = 600

    def __init__(self, api_key: str | None = None, model_id: str | None = None) -> None:
        ensure_env_loaded()
        key = api_key
        if key is None:
            key = env("MINDEE_API_KEY", "") or os.environ.get("MINDEE_API_KEY") or ""
        super().__init__("", key if isinstance(key, str) else "")
        self.disable_ssl_verif = False

        model = model_id
        if model is None or model == "":
            model = env("MINDEE_OCR_MODEL_ID", "") or os.environ.get("MINDEE_OCR_MODEL_ID") or ""
        self.model_id = model if isinstance(model, str) and model != "" else self.DEFAULT_MODEL_ID

        self._inline: dict[str, Any] | None = None
        self._remote_url: str | None = None
        self._language: str | None = None

    def set_file(
        self,
        file_path: str | Any,
        file_name: str | Any | None = None,
        input_file: Any | None = None,
    ) -> None:
        actual_input_file = None
        temp_path: str | None = None

        if not isinstance(file_path, str):
            actual_input_file = file_path
            if isinstance(file_name, str) and os.path.isfile(file_name):
                actual_path: str | None = file_name
                file_name = getattr(actual_input_file, "name", None)
            else:
                actual_path = None
                if not (isinstance(file_name, str) and file_name != ""):
                    file_name = getattr(actual_input_file, "name", "document")
        else:
            actual_path = file_path
            if file_name is not None and not isinstance(file_name, str):
                actual_input_file = file_name
                file_name = getattr(actual_input_file, "name", None)
            elif input_file is not None:
                actual_input_file = input_file
            if not isinstance(file_name, str) or file_name == "":
                file_name = os.path.basename(file_path)

        if actual_input_file is not None:
            size = getattr(actual_input_file, "size", None)
            if isinstance(size, int) and size > self.MAX_FILE_SIZE:
                self._reject_heavy_file(actual_input_file)

        try:
            if actual_path is None:
                if actual_input_file is None:
                    raise Exception(f"Cannot read file: {file_name}")
                temp_path = self._download_input_file(actual_input_file)
                actual_path = temp_path

            if not os.path.isfile(actual_path) or not os.access(actual_path, os.R_OK):
                raise Exception(f"Cannot read file: {file_name}")

            size = os.path.getsize(actual_path)
            if size > self.MAX_FILE_SIZE:
                self._reject_heavy_file(actual_input_file)

            mime = self._mime_for_name(str(file_name))
            with open(actual_path, "rb") as fh:
                data = fh.read()

            self._inline = {"mime": mime, "bytes": data, "name": str(file_name)}
            self._remote_url = None
        finally:
            if temp_path is not None and os.path.isfile(temp_path):
                try:
                    os.unlink(temp_path)
                except OSError:
                    pass

    def set_remote_file(self, file_id_or_url: str) -> None:
        file_ref = file_id_or_url.strip()
        if file_ref == "":
            raise ValueError("OCR file is required")
        if not file_ref.lower().startswith(("http://", "https://")):
            raise ValueError("OCR file must be an http(s) URL")
        path = urlparse(file_ref).path or file_ref
        self._mime_for_name(path)
        self._remote_url = file_ref
        self._inline = None

    def set_language(self, language: str) -> None:
        language = language.strip()
        if language == "":
            raise ValueError("Language must not be empty")
        self._language = language

    def process(self) -> dict[str, Any]:
        if self._inline is None and self._remote_url is None:
            raise ValueError("OCR file is required")
        pages = self._request_pages()
        return self._output_from_pages(pages)

    def _request_pages(self) -> list[dict[str, Any]]:
        if self.token == "":
            raise ValueError("MINDEE_API_KEY is required")
        if _MINDEE_SDK_AVAILABLE:
            return self._request_pages_sdk()
        return self._request_pages_http()

    def _request_pages_sdk(self) -> list[dict[str, Any]]:
        cleanup: list[str] = []
        try:
            input_source = self._input_source(cleanup)
            client = MindeeSdkClient(self.token)
            response = client.enqueue_and_get_result(
                OCRResponse,
                input_source,
                OCRParameters(self.model_id),
            )
            pages = getattr(getattr(getattr(response, "inference", None), "result", None), "pages", None)
            if not isinstance(pages, list) or pages == []:
                raise Exception("Empty OCR response")
            raw_pages = self._raw_pages_from_sdk(response)

            out: list[dict[str, Any]] = []
            for index, page in enumerate(pages):
                words: list[dict[str, Any]] = []
                for word in getattr(page, "words", []) or []:
                    polygon: list[list[float]] = []
                    poly = getattr(word, "polygon", None)
                    coords = poly.get_coordinates() if poly is not None and hasattr(poly, "get_coordinates") else None
                    if coords is None and poly is not None and hasattr(poly, "getCoordinates"):
                        coords = poly.getCoordinates()
                    for point in coords or []:
                        x = point.get_x() if hasattr(point, "get_x") else getattr(point, "x", None)
                        y = point.get_y() if hasattr(point, "get_y") else getattr(point, "y", None)
                        if x is not None and y is not None:
                            polygon.append([float(x), float(y)])
                    words.append(
                        {
                            "content": getattr(word, "content", ""),
                            "polygon": polygon,
                        }
                    )
                page_out: dict[str, Any] = {
                    "content": getattr(page, "content", ""),
                    "words": words,
                }
                orientation = self._page_orientation(raw_pages[index] if index < len(raw_pages) else None)
                if orientation is not None:
                    page_out["orientation"] = orientation
                out.append(page_out)
            return out
        finally:
            self._cleanup_paths(cleanup)

    def _request_pages_http(self) -> list[dict[str, Any]]:
        cleanup: list[str] = []
        try:
            timeout = httpx.Timeout(
                connect=2.0,
                read=float(self.timeout) if self.timeout > 0 else 180.0,
                write=60.0,
                pool=5.0,
            )
            headers = {"Authorization": self.token}
            with httpx.Client(
                timeout=timeout, follow_redirects=False, verify=not self.disable_ssl_verif
            ) as client:
                if self._inline is not None:
                    path = self._write_temp_file(
                        self._inline["bytes"], self._inline["name"], cleanup
                    )
                    with open(path, "rb") as fh:
                        files = {
                            "model_id": (None, self.model_id),
                            "file": (os.path.basename(path), fh, self._inline["mime"]),
                        }
                        enqueue = client.post(self.ENQUEUE_URL, headers=headers, files=files)
                else:
                    url = self._remote_url or ""
                    if url.lower().startswith("https://"):
                        enqueue = client.post(
                            self.ENQUEUE_URL,
                            headers=headers,
                            data={"model_id": self.model_id, "url": url},
                        )
                    else:
                        data = self._download(url)
                        if len(data) > self.MAX_FILE_SIZE:
                            raise Exception("Image file too heavy")
                        name = os.path.basename(urlparse(url).path or "document")
                        path = self._write_temp_file(data, name, cleanup)
                        mime = self._mime_for_name(name)
                        with open(path, "rb") as fh:
                            files = {
                                "model_id": (None, self.model_id),
                                "file": (os.path.basename(path), fh, mime),
                            }
                            enqueue = client.post(self.ENQUEUE_URL, headers=headers, files=files)

                self.last_response_code = enqueue.status_code
                if enqueue.status_code not in (200, 202):
                    raise Exception(enqueue.text or "Mindee enqueue failed")

                job = enqueue.json()
                polling_url = (job.get("job") or {}).get("polling_url") or job.get("polling_url")
                if not isinstance(polling_url, str) or polling_url == "":
                    raise Exception("Mindee enqueue did not return a polling URL")

                time.sleep(self.POLL_INITIAL_DELAY)
                result_url: str | None = None
                for _ in range(self.POLL_MAX_ATTEMPTS):
                    poll = client.get(polling_url, headers=headers)
                    self.last_response_code = poll.status_code
                    if poll.status_code in (301, 302, 303, 307, 308):
                        result_url = poll.headers.get("Location")
                        break
                    try:
                        body = poll.json()
                    except json.JSONDecodeError:
                        body = {}
                    job_body = body.get("job") if isinstance(body, dict) else None
                    if isinstance(job_body, dict):
                        status = str(job_body.get("status") or "").lower()
                        if status in ("failed", "error"):
                            raise Exception(job_body.get("error") or "OCR failed")
                        result_url = job_body.get("result_url")
                        if isinstance(result_url, str) and result_url:
                            break
                        if status in ("processed", "completed", "done") and result_url:
                            break
                    time.sleep(self.POLL_INTERVAL)

                if not isinstance(result_url, str) or result_url == "":
                    raise Exception("Empty OCR response")

                result = client.get(result_url, headers=headers)
                self.last_response_code = result.status_code
                if result.status_code < 200 or result.status_code > 299:
                    raise Exception(result.text or "Failed to fetch Mindee OCR result")
                payload = result.json()
                pages = (
                    ((payload.get("inference") or {}).get("result") or {}).get("pages")
                    if isinstance(payload, dict)
                    else None
                )
                if not isinstance(pages, list) or pages == []:
                    raise Exception("Empty OCR response")
                return [p for p in pages if isinstance(p, dict)]
        finally:
            self._cleanup_paths(cleanup)

    def _input_source(self, cleanup: list[str]) -> Any:
        if self._inline is not None:
            return PathInput(
                self._write_temp_file(self._inline["bytes"], self._inline["name"], cleanup)
            )

        url = self._remote_url or ""
        if url.lower().startswith("https://"):
            return UrlInputSource(url)

        data = self._download(url)
        if len(data) > self.MAX_FILE_SIZE:
            raise Exception("Image file too heavy")
        name = os.path.basename(urlparse(url).path or "document")
        return PathInput(self._write_temp_file(data, name, cleanup))

    def _write_temp_file(self, data: bytes, file_name: str, cleanup: list[str]) -> str:
        safe_name = os.path.basename(file_name.replace("\\", "/"))
        if safe_name in ("", ".", ".."):
            safe_name = "document"
        directory = Path(os.environ.get("TMPDIR", "/tmp")) / f"mindee_ocr_{secrets.token_hex(6)}"
        directory.mkdir(parents=True, exist_ok=True)
        path = directory / safe_name
        path.write_bytes(data)
        cleanup.append(str(path))
        return str(path)

    def _cleanup_paths(self, paths: list[str]) -> None:
        dirs: dict[str, bool] = {}
        for path in paths:
            if os.path.isfile(path):
                try:
                    os.unlink(path)
                except OSError:
                    pass
            dirs[os.path.dirname(path)] = True
        for directory in dirs:
            if os.path.isdir(directory):
                try:
                    os.rmdir(directory)
                except OSError:
                    pass

    def _output_from_pages(self, pages: list[dict[str, Any]]) -> dict[str, Any]:
        if pages == []:
            raise Exception("Empty OCR response")

        texts: list[str] = []
        boxes: list[dict[str, Any]] = []
        for index, page in enumerate(pages):
            if not isinstance(page, dict):
                continue
            content = page.get("content")
            if isinstance(content, str) and content != "":
                texts.append(content)
            for word in page.get("words") or []:
                if not isinstance(word, dict):
                    continue
                text = word.get("content")
                if not isinstance(text, str) or text == "":
                    continue
                fraction = self._fraction_box(word.get("polygon") or [])
                boxes.append(
                    {
                        "text": text,
                        "left": fraction["left"],
                        "top": fraction["top"],
                        "width": fraction["width"],
                        "height": fraction["height"],
                        "page": index,
                    }
                )

        if not texts:
            raise Exception("OCR response has no text")
        if not boxes:
            raise Exception("OCR response has no bounding boxes")

        result: dict[str, Any] = {
            "text": "\n".join(texts),
            "bounding_boxes": boxes,
            "usage": None,
        }
        rotation = self._rotation_from_pages(pages)
        if rotation is not None:
            result["rotation"] = rotation

        if self.LOG_RESPONSE:
            code = self.last_response_code or 200
            formatted = json.dumps(result, indent=2, ensure_ascii=False)
            logger.info("[MindeeOCR] Processed OCR Result (HTTP %d):\n%s", code, formatted)
        return result

    def _raw_pages_from_sdk(self, response: Any) -> list[Any]:
        raw_http = None
        if hasattr(response, "get_raw_http"):
            raw_http = response.get_raw_http()
        elif hasattr(response, "getRawHttp"):
            raw_http = response.getRawHttp()
        elif hasattr(response, "raw_http"):
            raw_http = response.raw_http
        if not isinstance(raw_http, str):
            return []
        try:
            raw = json.loads(raw_http)
        except json.JSONDecodeError:
            return []
        pages = ((raw.get("inference") or {}).get("result") or {}).get("pages")
        return list(pages) if isinstance(pages, list) else []

    def _page_orientation(self, page: Any) -> int | None:
        if not isinstance(page, dict):
            return None
        orientation = page.get("orientation", page.get("rotation"))
        if isinstance(orientation, dict):
            orientation = orientation.get("value", orientation.get("degrees"))
        if not isinstance(orientation, (int, float, str)):
            return None
        try:
            angle = int(orientation) % 360
        except (TypeError, ValueError):
            return None
        if angle < 0:
            angle += 360
        return angle if angle in (0, 90, 180, 270) else None

    def _rotation_from_pages(self, pages: list[dict[str, Any]]) -> int | None:
        best: int | None = None
        best_words = -1
        for page in pages:
            if not isinstance(page, dict):
                continue
            orientation = self._page_orientation(page)
            if orientation is None:
                continue
            words = 0
            for word in page.get("words") or []:
                if isinstance(word, dict) and isinstance(word.get("content"), str) and word["content"]:
                    words += 1
            if words > best_words:
                best_words = words
                best = (360 - orientation) % 360
        return best

    def _fraction_box(self, polygon: Any) -> dict[str, float]:
        empty = {"left": 0.0, "top": 0.0, "width": 0.0, "height": 0.0}
        if not isinstance(polygon, list) or polygon == []:
            return empty

        xs: list[float] = []
        ys: list[float] = []
        for point in polygon:
            if not isinstance(point, (list, tuple, dict)):
                continue
            if isinstance(point, dict):
                x = point.get("x")
                y = point.get("y")
            else:
                x = point[0] if len(point) > 0 else None
                y = point[1] if len(point) > 1 else None
            if not isinstance(x, (int, float)) or not isinstance(y, (int, float)):
                continue
            xs.append(float(x))
            ys.append(float(y))
        if not xs:
            return empty

        min_x, max_x = min(xs), max(xs)
        min_y, max_y = min(ys), max(ys)
        return {
            "left": min_x,
            "top": min_y,
            "width": max_x - min_x,
            "height": max_y - min_y,
        }

    def _download(self, url: str) -> bytes:
        timeout = httpx.Timeout(
            connect=2.0,
            read=float(self.timeout) if self.timeout > 0 else 30.0,
            write=30.0,
            pool=5.0,
        )
        with httpx.Client(
            timeout=timeout, follow_redirects=True, verify=not self.disable_ssl_verif
        ) as client:
            response = client.get(url)
        self.last_response_code = response.status_code
        if response.status_code < 200 or response.status_code > 299:
            raise Exception("Failed to download the OCR file")
        return response.content

    def _mime_for_name(self, file_name: str) -> str:
        mime = mime_type_for_filename(file_name).split(";", 1)[0].strip().lower()
        if mime != "application/pdf" and not mime.startswith("image/"):
            raise ValueError(f"Unsupported OCR file type: {file_name}")
        return mime

    def _reject_heavy_file(self, input_file: Any | None) -> None:
        if input_file is not None:
            append = getattr(input_file, "append_event", None) or getattr(
                input_file, "appendEvent", None
            )
            if callable(append):
                try:
                    append("Image file too heavy")
                except Exception as exc:
                    logger.error("%s", exc)
        raise Exception("Image file too heavy")

    def _download_input_file(self, input_file: Any) -> str:
        content_key = getattr(input_file, "content_key", None)
        key = content_key() if callable(content_key) else getattr(input_file, "contentKey", None)
        if callable(key):
            key = key()
        if not isinstance(key, str) or key == "":
            raise Exception(f"Cannot read file: {getattr(input_file, 'name', 'document')}")
        try:
            from pycorrai.store.object_store import ObjectStore
        except ImportError as exc:
            raise Exception("ObjectStore is required to load InputFile content") from exc
        data = ObjectStore().get_bytes(key)
        tmp = Path(os.environ.get("TMPDIR", "/tmp")) / f"mindee_ocr_if_{os.getpid()}_{id(input_file)}"
        tmp.write_bytes(data)
        return str(tmp)
