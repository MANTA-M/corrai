"""HTTP REST client mirroring PHP RestClient / LlmClient."""

from __future__ import annotations

import json
import logging
import re
import time
from typing import Any

import httpx

from pycorrai.config import env

logger = logging.getLogger(__name__)


class HttpError(Exception):
    """Raised on non-2xx HTTP responses (code is the status)."""

    def __init__(self, message: str, code: int = 0):
        super().__init__(message)
        self.code = code

    def __str__(self) -> str:
        return super().__str__()


class AIError(HttpError):
    """Generic AI error (e.g. provider 402 credit exhaustion)."""

    def __init__(self, message: str = "AI error", code: int = 500, cause: BaseException | None = None):
        super().__init__(message, code)
        self.__cause__ = cause


class RestClient:
    def __init__(self, base_url: str = "", token: str = ""):
        self.base_url = base_url
        self.token = token
        self.disable_ssl_verif = True
        self.compress = False
        self.common_headers: list[str] = []
        self.send_length = False
        self.verbose = True
        self.timeout = 0
        self.last_response_code = 0

    def should_log(self) -> bool:
        return self.verbose

    def query(
        self,
        path: str,
        method: str = "GET",
        headers: list[str] | dict[str, str] | None = None,
        post_data: Any = None,
        *,
        throw_on_error: bool = True,
        files: dict[str, Any] | None = None,
    ) -> str | None:
        url = path if path.startswith("http://") or path.startswith("https://") else self.base_url + path
        hdrs = self._merge_headers(headers, post_data is not None and files is None)
        content: bytes | None = None
        if post_data is not None and files is None:
            if isinstance(post_data, (bytes, bytearray)):
                content = bytes(post_data)
            elif isinstance(post_data, str):
                content = post_data.encode("utf-8")
            else:
                content = json.dumps(post_data, ensure_ascii=False).encode("utf-8")
            if self.send_length and "Content-Length" not in {k.lower(): k for k in hdrs}:
                hdrs["Content-Length"] = str(len(content))

        if self.should_log():
            logger.info("REST QUERY %s %s", method, path)
            if content is not None:
                self._log_payload(content.decode("utf-8", errors="replace"), hdrs.get("Content-Type", "application/json"), method)

        timeout = httpx.Timeout(
            connect=2.0,
            read=float(self.timeout) if self.timeout > 0 else 60.0,
            write=60.0,
            pool=5.0,
        )
        verify = not self.disable_ssl_verif
        self._log_send_start(method, path)
        started = time.perf_counter()
        try:
            with httpx.Client(timeout=timeout, verify=verify) as client:
                if files is not None:
                    response = client.request(
                        method, url, headers=hdrs, data=post_data or {}, files=files
                    )
                else:
                    response = client.request(method, url, headers=hdrs, content=content)
        except Exception:
            duration_ms = max(0, round((time.perf_counter() - started) * 1000))
            self._log_send_end(method, path, duration_ms, 0)
            raise

        self.last_response_code = response.status_code
        duration_ms = max(0, round((time.perf_counter() - started) * 1000))
        self._log_send_end(method, path, duration_ms, response.status_code)
        body = response.text
        content_type = response.headers.get("content-type", "")
        is_json = "json" in content_type or (body[:1] == "{")
        if self.should_log() or response.status_code < 200 or response.status_code > 299:
            self._log_response(body, response.status_code, is_json)

        if throw_on_error and (response.status_code < 200 or response.status_code > 299):
            raise HttpError(body or f"HTTP {response.status_code}", response.status_code)
        if body == "":
            return None
        return body

    def query_array(
        self,
        path: str,
        method: str = "GET",
        headers: list[str] | dict[str, str] | None = None,
        post_data: Any = None,
    ) -> dict[str, Any] | list[Any] | None:
        raw = self.query(path, method, headers, post_data)
        if raw is None:
            return None
        normalized = raw.replace(":NaN", ':"NaN"')
        decoded = json.loads(normalized)
        return decoded

    def _merge_headers(
        self,
        headers: list[str] | dict[str, str] | None,
        with_json_defaults: bool,
    ) -> dict[str, str]:
        out: dict[str, str] = {}
        if isinstance(headers, dict):
            out.update(headers)
        elif isinstance(headers, list):
            for item in headers:
                if ":" in item:
                    k, v = item.split(":", 1)
                    out[k.strip()] = v.strip()
        if not out and with_json_defaults:
            out["Content-Type"] = "application/json"
            out["Accept"] = "application/json"
        if self.token:
            out["Authorization"] = f"Bearer {self.token}"
        for item in self.common_headers:
            if ":" in item:
                k, v = item.split(":", 1)
                out[k.strip()] = v.strip()
        # REST_TRACE_LEVEL reserved for future curl-style verbose
        _ = env("REST_TRACE_LEVEL", "none")
        return out

    def _log_send_start(self, method: str, path: str) -> None:
        if self.should_log():
            logger.info("[RestClient] Start %s %s", method, path)

    def _log_send_end(
        self, method: str, path: str, duration_ms: int, response_code: int
    ) -> None:
        if self.should_log():
            logger.info(
                "[RestClient] End %s %s (%d ms, HTTP %d)",
                method,
                path,
                duration_ms,
                response_code,
            )

    def _log_payload(self, payload: str, content_type: str, method: str) -> None:
        logger.info("[RestClient] %s Payload (%s):\n%s", method, content_type, payload[:2000])

    def _log_response(self, response: str, response_code: int, is_json: bool) -> None:
        if not response:
            logger.info("[RestClient] Response (HTTP %d): Empty or failed", response_code)
            return
        preview = response if len(response) < 4000 else response[:4000] + "..."
        logger.info("[RestClient] Response (HTTP %d):\n%s", response_code, preview)


class LlmClient(RestClient):
    BASE64_REDACT_MIN_LEN = 200
    NEWLINE_PLACEHOLDER = "###CORRAI_LOG_NL###"

    def __init__(self, base_url: str = "", token: str = ""):
        super().__init__(base_url, token)
        self.debug = True
        self.send_length = True
        self.verbose = True
        self.timeout = 180

    def should_log(self) -> bool:
        return self.verbose or self.debug

    def _log_send_start(self, method: str, path: str) -> None:
        if self.should_log():
            logger.info("[LlmClient] Start %s %s", method, path)

    def _log_send_end(
        self, method: str, path: str, duration_ms: int, response_code: int
    ) -> None:
        if self.should_log():
            logger.info(
                "[LlmClient] End %s %s (%d ms, HTTP %d)",
                method,
                path,
                duration_ms,
                response_code,
            )

    def _log_payload(self, payload: str, content_type: str, method: str) -> None:
        log_message = f"[LlmClient] {method} Payload ({content_type}):"
        if "json" in content_type:
            try:
                decoded = json.loads(payload)
                redacted = self.redact_binary_for_log(decoded)
                formatted = json.dumps(redacted, indent=2, ensure_ascii=False)
                logger.info("%s\n%s", log_message, formatted)
            except json.JSONDecodeError:
                logger.info("%s\n%s", log_message, self.redact_binary_string(payload))
        else:
            logger.info("%s\n%s", log_message, self.redact_binary_string(payload))

    def _log_response(self, response: str, response_code: int, is_json: bool) -> None:
        if not response:
            logger.info("[LlmClient] Response (HTTP %d): Empty or failed", response_code)
            return
        log_message = f"[LlmClient] Response (HTTP {response_code}):"
        if is_json:
            logger.info("%s\n%s", log_message, self.format_response_for_log(response))
        else:
            logger.info("%s\n%s", log_message, self.redact_binary_string(response))

    def format_response_for_log(self, response: str) -> str:
        try:
            decoded = json.loads(response)
        except json.JSONDecodeError:
            return self.redact_binary_string(response)
        if not isinstance(decoded, dict):
            return self.redact_binary_string(response)
        has_replaced = self._process_choices_for_log(decoded)
        redacted = self.redact_binary_for_log(decoded)
        formatted = json.dumps(redacted, indent=2, ensure_ascii=False)
        if has_replaced:
            formatted = formatted.replace(self.NEWLINE_PLACEHOLDER, "\n")
        return formatted

    def _process_choices_for_log(self, decoded: dict[str, Any]) -> bool:
        choices = decoded.get("choices")
        if not isinstance(choices, list):
            return False
        has_replaced = False
        for choice in choices:
            if not isinstance(choice, dict):
                continue
            message = choice.get("message")
            if not isinstance(message, dict):
                continue
            content = message.get("content")
            if not isinstance(content, str):
                continue
            trimmed = content.strip()
            if trimmed.startswith("{"):
                cleaned = content.replace('\\"', '"')
                try:
                    parsed = json.loads(cleaned)
                    message["content"] = parsed
                    continue
                except json.JSONDecodeError:
                    pass
            if "\n" in content or "\\n" in content or "\r" in content:
                message["content"] = (
                    content.replace("\r\n", self.NEWLINE_PLACEHOLDER)
                    .replace("\n", self.NEWLINE_PLACEHOLDER)
                    .replace("\r", self.NEWLINE_PLACEHOLDER)
                    .replace("\\r\\n", self.NEWLINE_PLACEHOLDER)
                    .replace("\\n", self.NEWLINE_PLACEHOLDER)
                    .replace("\\r", self.NEWLINE_PLACEHOLDER)
                )
                has_replaced = True
        return has_replaced

    def redact_binary_for_log(self, value: Any) -> Any:
        if isinstance(value, str):
            return self.redact_binary_string(value)
        if isinstance(value, list):
            return [self.redact_binary_for_log(item) for item in value]
        if isinstance(value, dict):
            return {k: self.redact_binary_for_log(v) for k, v in value.items()}
        return value

    def redact_binary_string(self, value: str) -> str:
        match = re.match(r"^(data:[^;]+;base64,)(.+)$", value, re.DOTALL)
        if match:
            return match.group(1) + f"[omitted {len(match.group(2))} chars]"
        if len(value) >= self.BASE64_REDACT_MIN_LEN and re.fullmatch(r"[A-Za-z0-9+/=\s]+", value):
            return f"[base64 omitted, {len(value)} chars]"
        return value


def raise_ai_on_402(exc: BaseException) -> None:
    code = getattr(exc, "code", None)
    if code is None and isinstance(exc, HttpError):
        code = exc.code
    if int(code or 0) == 402:
        logger.error("LLM Credit payment required")
        raise AIError("AI error", 500, exc) from exc
    raise exc
