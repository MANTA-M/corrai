"""Google Cloud Vision OCR, mirroring PHP Google/Vision.php."""

from __future__ import annotations

import base64
import json
import logging
import os
import re
import time
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

import httpx
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding

from pycorrai.ocr.providers.missing_credentials import MissingCredentialsException
from pycorrai.config import ensure_env_loaded, env
from pycorrai.http.rest import LlmClient
from pycorrai.image.resize import mime_type_for_filename

logger = logging.getLogger(__name__)


class Vision(LlmClient):
    """Drop-in replacement for Eden GoogleOCRClient using Cloud Vision."""

    IMAGES_ANNOTATE_URL = "https://vision.googleapis.com/v1/images:annotate"
    FILES_ANNOTATE_URL = "https://vision.googleapis.com/v1/files:annotate"
    TOKEN_URL = "https://oauth2.googleapis.com/token"
    SCOPE = "https://www.googleapis.com/auth/cloud-vision"
    FEATURE = "DOCUMENT_TEXT_DETECTION"
    MAX_FILE_SIZE = 10 * 1024 * 1024  # 10 Mo (10 MB)
    LOG_RESPONSE = False

    PDF_PAGES_PER_REQUEST = 5
    TOKEN_LIFETIME = 3600

    _cached_credentials: dict[str, str] | None = None
    _cached_token: str | None = None
    _cached_token_expires_at: int = 0

    def __init__(self, keys_path: str | None = None) -> None:
        ensure_env_loaded()
        super().__init__("")
        self.disable_ssl_verif = False
        env_path = (
            env("GOOGLE_APPLICATION_CREDENTIALS", "")
            or env("GOOGLE_KEYS_PATH", "")
            or os.environ.get("GOOGLE_APPLICATION_CREDENTIALS")
            or os.environ.get("GOOGLE_KEYS_PATH")
            or None
        )
        default_path = Path(__file__).resolve().parents[5] / "google-keys.json"
        self.keys_path = keys_path or env_path or str(default_path)
        self._inline: dict[str, Any] | None = None
        self._remote_uri: str | None = None
        self._remote_mime: str | None = None
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

            self._inline = {"mime": mime, "bytes": data}
            self._remote_uri = None
            self._remote_mime = None
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
        if not re.match(r"^(https?://|gs://)", file_ref, re.IGNORECASE):
            raise ValueError("OCR file must be an http(s) or gs:// URL")
        path = urlparse(file_ref).path or file_ref
        self._remote_uri = file_ref
        self._remote_mime = self._mime_for_name(path)
        self._inline = None

    def set_language(self, language: str) -> None:
        language = language.strip()
        if language == "":
            raise ValueError("Language must not be empty")
        self._language = language

    def process(self) -> dict[str, Any]:
        if self._inline is None and self._remote_uri is None:
            raise ValueError("OCR file is required")

        self.authorize()
        mime = (
            (self._inline or {}).get("mime")
            or self._remote_mime
            or "application/octet-stream"
        )
        if self._is_document(str(mime)):
            return self._annotate_document(str(mime))
        return self._annotate_image()

    def authorize(self) -> None:
        self.token = self._access_token()

    @staticmethod
    def isDocumentMime(mime: str) -> bool:
        mime = mime.split(";", 1)[0].strip().lower()
        return mime in ("application/pdf", "image/tiff")

    def buildImagesAnnotatePostData(
        self, data: bytes, language: str | None = None
    ) -> dict[str, Any]:
        request: dict[str, Any] = {
            "image": {"content": base64.b64encode(data).decode("ascii")},
            "features": [{"type": self.FEATURE}],
        }
        if language:
            request["imageContext"] = {"languageHints": [language]}
        return {"requests": [request]}

    def outputFromImagesAnnotateBody(self, body: str) -> dict[str, Any]:
        try:
            response = json.loads(body)
        except json.JSONDecodeError as exc:
            raise Exception("Empty OCR response") from exc
        if not isinstance(response, dict):
            raise Exception("Empty OCR response")
        annotation = (response.get("responses") or [None])[0]
        if not isinstance(annotation, dict):
            raise Exception("Empty OCR response")
        return self._output_from_annotations([annotation])

    def _access_token(self) -> str:
        credentials = self._credentials()
        now = int(time.time())
        cls = type(self)
        if (
            cls._cached_token is not None
            and cls._cached_credentials is not None
            and cls._cached_credentials.get("client_email") == credentials["client_email"]
            and cls._cached_token_expires_at > now + 60
        ):
            return cls._cached_token

        token = self._fetch_access_token(credentials, now)
        cls._cached_credentials = credentials
        cls._cached_token = token["access_token"]
        cls._cached_token_expires_at = now + int(token["expires_in"])
        return cls._cached_token

    def _require_credentials_file(self) -> None:
        if not os.path.isfile(self.keys_path):
            raise MissingCredentialsException(
                f"Google Vision credentials file not found: {self.keys_path}"
            )
        if not os.access(self.keys_path, os.R_OK):
            raise MissingCredentialsException(
                f"Google Vision credentials file is not readable: {self.keys_path}"
            )

    def _credentials(self) -> dict[str, str]:
        raw = env("GOOGLE_KEYS_JSON", "") or os.environ.get("GOOGLE_KEYS_JSON") or ""
        if not raw:
            self._require_credentials_file()
            raw = Path(self.keys_path).read_text(encoding="utf-8")
        try:
            decoded = json.loads(raw)
        except json.JSONDecodeError as exc:
            raise Exception("Google Vision credentials are not valid JSON") from exc
        if not isinstance(decoded, dict):
            raise Exception("Google Vision credentials are not valid JSON")
        email = decoded.get("client_email")
        key = decoded.get("private_key")
        if not isinstance(email, str) or email == "" or not isinstance(key, str) or key == "":
            raise Exception(
                "Google Vision credentials must include client_email and private_key"
            )
        token_uri = decoded.get("token_uri") or self.TOKEN_URL
        if not isinstance(token_uri, str) or token_uri == "":
            token_uri = self.TOKEN_URL
        return {
            "client_email": email,
            "private_key": key,
            "token_uri": token_uri,
        }

    def _fetch_access_token(
        self, credentials: dict[str, str], now: int
    ) -> dict[str, Any]:
        assertion = self._sign_jwt_assertion(credentials, now)

        timeout = httpx.Timeout(
            connect=2.0,
            read=float(self.timeout) if self.timeout > 0 else 30.0,
            write=30.0,
            pool=5.0,
        )
        with httpx.Client(timeout=timeout, verify=not self.disable_ssl_verif) as client:
            response = client.post(
                credentials["token_uri"],
                headers={"Content-Type": "application/x-www-form-urlencoded"},
                data={
                    "grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer",
                    "assertion": assertion,
                },
            )
        self.last_response_code = response.status_code
        body = response.text
        if self.should_log() or response.status_code < 200 or response.status_code > 299:
            self._log_response(body, response.status_code, True)

        try:
            decoded = json.loads(body) if body else None
        except json.JSONDecodeError:
            decoded = None
        if response.status_code < 200 or response.status_code > 299 or not isinstance(decoded, dict):
            message = None
            if isinstance(decoded, dict):
                message = decoded.get("error_description") or decoded.get("error")
            raise Exception(
                message
                if isinstance(message, str) and message
                else "Google Vision token request failed"
            )
        access_token = decoded.get("access_token")
        if not isinstance(access_token, str) or access_token == "":
            raise Exception("Google Vision token response has no access_token")
        expires_in = decoded.get("expires_in", self.TOKEN_LIFETIME)
        return {
            "access_token": access_token,
            "expires_in": expires_in if isinstance(expires_in, int) else self.TOKEN_LIFETIME,
        }

    def _annotate_image(self) -> dict[str, Any]:
        image: dict[str, Any] = {}
        if self._inline is not None:
            image["content"] = base64.b64encode(self._inline["bytes"]).decode("ascii")
        else:
            image["source"] = {"imageUri": self._remote_uri}

        request: dict[str, Any] = {
            "image": image,
            "features": [{"type": self.FEATURE}],
        }
        self._apply_language(request)

        response = self.query_array(
            self.IMAGES_ANNOTATE_URL,
            "POST",
            self.common_headers,
            {"requests": [request]},
        )
        if response is None or not isinstance(response, dict):
            raise Exception("Empty OCR response")
        annotation = (response.get("responses") or [None])[0]
        if not isinstance(annotation, dict):
            raise Exception("Empty OCR response")
        return self._output_from_annotations([annotation])

    def _annotate_document(self, mime: str) -> dict[str, Any]:
        source = self._document_source(mime)
        first = self._annotate_document_pages(source, [1])
        total = first.get("totalPages", 1)
        if not isinstance(total, int) or total < 1:
            total = 1
        annotations = list(first["annotations"])
        start = 2
        while start <= total:
            end = min(total, start + self.PDF_PAGES_PER_REQUEST - 1)
            pages = list(range(start, end + 1))
            batch = self._annotate_document_pages(source, pages)
            annotations.extend(batch["annotations"])
            start += self.PDF_PAGES_PER_REQUEST
        return self._output_from_annotations(annotations)

    def _annotate_document_pages(
        self, source: dict[str, Any], pages: list[int]
    ) -> dict[str, Any]:
        request: dict[str, Any] = {
            "inputConfig": source,
            "features": [{"type": self.FEATURE}],
            "pages": pages,
        }
        self._apply_language(request)

        response = self.query_array(
            self.FILES_ANNOTATE_URL,
            "POST",
            self.common_headers,
            {"requests": [request]},
        )
        if response is None or not isinstance(response, dict):
            raise Exception("Empty OCR response")
        file_response = (response.get("responses") or [None])[0]
        if not isinstance(file_response, dict):
            raise Exception("Empty OCR response")
        if "error" in file_response:
            raise Exception(self._google_error(file_response["error"]))
        annotations = file_response.get("responses")
        if not isinstance(annotations, list):
            raise Exception("Empty OCR response")
        page_annotations = [a for a in annotations if isinstance(a, dict)]
        total_pages = file_response.get("totalPages", 1)
        return {
            "totalPages": total_pages if isinstance(total_pages, int) else 1,
            "annotations": page_annotations,
        }

    def _document_source(self, mime: str) -> dict[str, Any]:
        if self._inline is not None:
            return {
                "mimeType": mime,
                "content": base64.b64encode(self._inline["bytes"]).decode("ascii"),
            }

        uri = self._remote_uri or ""
        if uri.lower().startswith("gs://"):
            return {"mimeType": mime, "gcsSource": {"uri": uri}}

        data = self._download(uri)
        if len(data) > self.MAX_FILE_SIZE:
            raise Exception("Image file too heavy")
        return {
            "mimeType": mime,
            "content": base64.b64encode(data).decode("ascii"),
        }

    def _output_from_annotations(self, annotations: list[dict[str, Any]]) -> dict[str, Any]:
        texts: list[str] = []
        boxes: list[dict[str, Any]] = []
        for index, annotation in enumerate(annotations):
            if "error" in annotation:
                raise Exception(self._google_error(annotation["error"]))
            page_number = (annotation.get("context") or {}).get("pageNumber")
            page_index = page_number - 1 if isinstance(page_number, int) and page_number >= 1 else index

            full = annotation.get("fullTextAnnotation")
            if not isinstance(full, dict):
                continue
            text = full.get("text")
            if isinstance(text, str) and text != "":
                texts.append(text)
            for page in full.get("pages") or []:
                if isinstance(page, dict):
                    boxes.extend(self._words_from_page(page, page_index))

        if not texts:
            raise Exception("OCR response has no text")
        if not boxes:
            raise Exception("OCR response has no bounding boxes")

        result: dict[str, Any] = {
            "text": "\n".join(texts),
            "bounding_boxes": boxes,
            "usage": None,
        }
        if self.LOG_RESPONSE:
            code = self.last_response_code or 200
            formatted = json.dumps(result, indent=2, ensure_ascii=False)
            logger.info("[Vision] Processed OCR Result (HTTP %d):\n%s", code, formatted)
        return result

    def _words_from_page(self, page: dict[str, Any], page_index: int) -> list[dict[str, Any]]:
        width = self._dimension(page.get("width"))
        height = self._dimension(page.get("height"))
        words: list[dict[str, Any]] = []
        for block in page.get("blocks") or []:
            if not isinstance(block, dict):
                continue
            for paragraph in block.get("paragraphs") or []:
                if not isinstance(paragraph, dict):
                    continue
                for word in paragraph.get("words") or []:
                    if not isinstance(word, dict):
                        continue
                    text = ""
                    for symbol in word.get("symbols") or []:
                        if isinstance(symbol, dict) and isinstance(symbol.get("text"), str):
                            text += symbol["text"]
                    if text == "":
                        continue
                    box = word.get("boundingBox") or {}
                    fraction = self._fraction_box(
                        box if isinstance(box, dict) else {}, width, height
                    )
                    words.append(
                        {
                            "text": text,
                            "left": fraction["left"],
                            "top": fraction["top"],
                            "width": fraction["width"],
                            "height": fraction["height"],
                            "page": page_index,
                        }
                    )
        return words

    def _fraction_box(
        self, poly: dict[str, Any], page_width: float, page_height: float
    ) -> dict[str, float]:
        normalized = poly.get("normalizedVertices")
        use_normalized = isinstance(normalized, list) and normalized != []
        vertices = normalized if use_normalized else (poly.get("vertices") or [])
        if not isinstance(vertices, list):
            vertices = []

        xs: list[float] = []
        ys: list[float] = []
        for vertex in vertices:
            if not isinstance(vertex, dict):
                continue
            xs.append(self._dimension(vertex.get("x", 0)))
            ys.append(self._dimension(vertex.get("y", 0)))
        if not xs:
            return {"left": 0.0, "top": 0.0, "width": 0.0, "height": 0.0}

        min_x, max_x = min(xs), max(xs)
        min_y, max_y = min(ys), max(ys)
        if use_normalized:
            return {
                "left": min_x,
                "top": min_y,
                "width": max_x - min_x,
                "height": max_y - min_y,
            }
        if page_width <= 0 or page_height <= 0:
            raise Exception("OCR response has no page size")
        return {
            "left": min_x / page_width,
            "top": min_y / page_height,
            "width": (max_x - min_x) / page_width,
            "height": (max_y - min_y) / page_height,
        }

    def _apply_language(self, request: dict[str, Any]) -> None:
        if self._language is not None:
            request["imageContext"] = {"languageHints": [self._language]}

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

    def _is_document(self, mime: str) -> bool:
        return self.isDocumentMime(mime)

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

    def _google_error(self, error: Any) -> str:
        if isinstance(error, str) and error != "":
            return error
        if isinstance(error, dict):
            message = error.get("message")
            if isinstance(message, str) and message != "":
                return message
        return "OCR failed"

    def _dimension(self, value: Any) -> float:
        if isinstance(value, (int, float)):
            return float(value)
        if isinstance(value, str) and value.replace(".", "", 1).isdigit():
            return float(value)
        return 0.0

    def _sign_jwt_assertion(self, credentials: dict[str, str], now: int) -> str:
        header = self._base64_url(
            json.dumps({"alg": "RS256", "typ": "JWT"}, separators=(",", ":")).encode("utf-8")
        )
        claim = self._base64_url(
            json.dumps(
                {
                    "iss": credentials["client_email"],
                    "scope": self.SCOPE,
                    "aud": credentials["token_uri"],
                    "iat": now,
                    "exp": now + self.TOKEN_LIFETIME,
                },
                separators=(",", ":"),
            ).encode("utf-8")
        )
        unsigned = f"{header}.{claim}".encode("ascii")
        private_key = serialization.load_pem_private_key(
            credentials["private_key"].encode("utf-8"),
            password=None,
        )
        signature = private_key.sign(unsigned, padding.PKCS1v15(), hashes.SHA256())
        return f"{header}.{claim}." + self._base64_url(signature)

    @staticmethod
    def _base64_url(data: bytes) -> str:
        return base64.urlsafe_b64encode(data).decode("ascii").rstrip("=")

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
        tmp = Path(os.environ.get("TMPDIR", "/tmp")) / f"vision_ocr_{os.getpid()}_{id(input_file)}"
        tmp.write_bytes(data)
        return str(tmp)
