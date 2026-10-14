"""Google OCR through Eden AI, mirroring PHP Eden/GoogleOCRClient.php."""

from __future__ import annotations

import json
import logging
import os
from pathlib import Path
from typing import Any

from pycorrai.config import ensure_env_loaded, env
from pycorrai.http.rest import AIError, HttpError, LlmClient, raise_ai_on_402
from pycorrai.image.resize import mime_type_for_filename

logger = logging.getLogger(__name__)


class GoogleOCRClient(LlmClient):
    """Eden AI wrapper around Google OCR (model ocr/ocr/google)."""

    UNIVERSAL_AI_URL = "https://api.edenai.run/v3/universal-ai/"
    UPLOAD_URL = "https://api.edenai.run/v3/upload"
    MODEL = "ocr/ocr/google"
    MAX_FILE_SIZE = 10 * 1024 * 1024  # 10 Mo (10 MB)

    def __init__(self) -> None:
        ensure_env_loaded()
        super().__init__("", env("EDENAI_API_KEY", ""))
        self.payload: dict[str, Any] = {
            "model": self.MODEL,
            "input": {},
            "show_original_response": False,
        }

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
                if isinstance(file_name, str) and file_name != "":
                    pass
                else:
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

            mime = mime_type_for_filename(str(file_name)).split(";", 1)[0].strip().lower()
            if mime != "application/pdf" and not mime.startswith("image/"):
                raise ValueError(f"Unsupported OCR file type: {file_name}")

            self.payload["input"]["file"] = self._upload(actual_path, str(file_name), mime)
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
        self.payload["input"]["file"] = file_ref

    def set_language(self, language: str) -> None:
        language = language.strip()
        if language == "":
            raise ValueError("Language must not be empty")
        self.payload["input"]["language"] = language

    def process(self) -> dict[str, Any]:
        file_ref = self.payload.get("input", {}).get("file")
        if not isinstance(file_ref, str) or file_ref == "":
            raise ValueError("OCR file is required")

        response = self._query_model()
        if response is None:
            raise Exception("Empty OCR response")
        if response.get("status") == "fail":
            raise Exception(self._failure_message(response))

        output = response["output"] if isinstance(response.get("output"), dict) else response
        if not isinstance(output.get("text"), str):
            raise Exception("OCR response has no text")
        if not isinstance(output.get("bounding_boxes"), list):
            raise Exception("OCR response has no bounding boxes")
        return output

    def _failure_message(self, response: dict[str, Any]) -> str:
        error = response.get("error")
        if isinstance(error, str) and error != "":
            return error
        if isinstance(error, dict):
            detail = error.get("message") or error.get("detail")
            if isinstance(detail, str) and detail != "":
                return detail
        return "OCR failed"

    def _query_model(self) -> dict[str, Any] | None:
        try:
            result = self.query_array(
                self.UNIVERSAL_AI_URL, "POST", self.common_headers, self.payload
            )
            return result if isinstance(result, dict) else None
        except Exception as th:
            raise_ai_on_402(th)

    def _upload(self, file_path: str, file_name: str, mime: str) -> str:
        try:
            with open(file_path, "rb") as fh:
                files = {"file": (file_name, fh, mime)}
                raw = self.query(
                    self.UPLOAD_URL,
                    "POST",
                    {"Accept": "application/json"},
                    {"purpose": "ocr", "expires_in_days": "1"},
                    files=files,
                )
        except HttpError as exc:
            if exc.code == 402:
                logger.error("LLM Credit payment required")
                raise AIError("AI error", 500, exc) from exc
            message = self._upload_error(_safe_json(str(exc)), str(exc))
            raise HttpError(message, exc.code) from exc

        decoded = _safe_json(raw or "")
        file_id = decoded.get("file_id") if isinstance(decoded, dict) else None
        if not isinstance(file_id, str) or file_id == "":
            raise Exception("Eden AI upload did not return a file id")
        return file_id

    def _upload_error(self, decoded: dict[str, Any] | None, body: str) -> str:
        detail = decoded.get("detail") if isinstance(decoded, dict) else None
        if isinstance(detail, str) and detail != "":
            return detail
        if isinstance(detail, (list, dict)):
            try:
                encoded = json.dumps(detail)
            except (TypeError, ValueError):
                encoded = ""
            if encoded:
                return encoded
        return body if body else "Eden AI upload failed"

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
        tmp = Path(os.environ.get("TMPDIR", "/tmp")) / f"eden_ocr_{os.getpid()}_{id(input_file)}"
        tmp.write_bytes(data)
        return str(tmp)

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


def _safe_json(body: str) -> dict[str, Any] | None:
    try:
        decoded = json.loads(body)
    except json.JSONDecodeError:
        return None
    return decoded if isinstance(decoded, dict) else None
