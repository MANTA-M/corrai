"""Shared add_file strategies for OpenRouter clients."""

from __future__ import annotations

import base64
from collections.abc import Callable
from pathlib import Path
from typing import TYPE_CHECKING

from pycorrai.image.resize import (
    ImageRedimensioner,
    image_mime_from_bytes,
    mime_type_for_filename,
)

if TYPE_CHECKING:
    from .openrouter_client import OpenrouterClient

AddFileStrategy = Callable[["OpenrouterClient", str, str], None]


def _read_file_bytes(file_path: str, file_name: str) -> bytes:
    try:
        return Path(file_path).read_bytes()
    except OSError as e:
        raise Exception(f"Cannot read file: {file_name}") from e


def _mime_for_filename(file_name: str) -> str:
    return mime_type_for_filename(file_name).split(";")[0].strip().lower()


def enable_pdf_parser(client: OpenrouterClient, engine: str) -> None:
    for plugin in client.payload.get("plugins") or []:
        if (plugin.get("id") or "") == "file-parser":
            return
    plugins = client.payload.setdefault("plugins", [])
    plugins.append(
        {
            "id": "file-parser",
            "pdf": {"engine": engine},
        }
    )


def image_or_pdf_as_image_url(
    client: OpenrouterClient, file_path: str, file_name: str
) -> None:
    """Gemini Flash style: images and PDFs both as image_url data URLs."""
    data = _read_file_bytes(file_path, file_name)
    mime = _mime_for_filename(file_name)
    user_content = client._get_user_content()
    if mime.startswith("image/"):
        user_content.append(
            {
                "type": "image_url",
                "image_url": {
                    "url": f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
                },
            }
        )
        return

    user_content.append(
        {
            "type": "image_url",
            "image_url": {
                "url": "data:application/pdf;base64," + base64.b64encode(data).decode("ascii")
            },
        }
    )


def pdf_as_file_else_image_url_else_text(
    client: OpenrouterClient,
    file_path: str,
    file_name: str,
    *,
    pdf_engine: str = "mistral-ocr",
) -> None:
    """GPT-4o mini style: PDF as file (+ parser), image as image_url, else text."""
    data = _read_file_bytes(file_path, file_name)
    mime = _mime_for_filename(file_name)
    data_url = f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
    user_content = client._get_user_content()

    if mime == "application/pdf":
        enable_pdf_parser(client, pdf_engine)
        user_content.append(
            {
                "type": "file",
                "file": {
                    "filename": file_name,
                    "file_data": data_url,
                },
            }
        )
        return

    if mime.startswith("image/"):
        user_content.append({"type": "image_url", "image_url": {"url": data_url}})
        return

    user_content.append(
        {
            "type": "text",
            "text": file_name + ":\n" + data.decode("utf-8", errors="replace"),
        }
    )


def pdf_as_file_mistral_ocr(
    client: OpenrouterClient, file_path: str, file_name: str
) -> None:
    pdf_as_file_else_image_url_else_text(
        client, file_path, file_name, pdf_engine="mistral-ocr"
    )


def pdf_as_file_pdf_text(
    client: OpenrouterClient, file_path: str, file_name: str
) -> None:
    pdf_as_file_else_image_url_else_text(
        client, file_path, file_name, pdf_engine="pdf-text"
    )


def claude_resized_image_or_pdf_file_or_text(
    client: OpenrouterClient, file_path: str, file_name: str
) -> None:
    """Claude Sonnet: resize images, PDF as file (no parser plugin), else text."""
    data = _read_file_bytes(file_path, file_name)
    mime = _mime_for_filename(file_name)
    user_content = client._get_user_content()
    detected = image_mime_from_bytes(data)

    if mime.startswith("image/") or detected is not None:
        client.rescale = 1.0  # type: ignore[attr-defined]
        if detected is not None:
            max_dim = getattr(type(client), "MAX_IMAGE_DIMENTION", 1568)
            redimentioner = ImageRedimensioner(max_dim, data)
            client.rescale = redimentioner.factor  # type: ignore[attr-defined]
            data = redimentioner.image
            mime = image_mime_from_bytes(data) or mime
        data_url = f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
        user_content.append({"type": "image_url", "image_url": {"url": data_url}})
        return

    if mime != "application/pdf":
        user_content.append(
            {
                "type": "text",
                "text": file_name + ":\n" + data.decode("utf-8", errors="replace"),
            }
        )
        return

    data_url = f"data:{mime};base64," + base64.b64encode(data).decode("ascii")
    user_content.append(
        {
            "type": "file",
            "file": {
                "filename": file_name,
                "file_data": data_url,
            },
        }
    )


def gemini_flash_lite_image_or_text(
    client: OpenrouterClient, file_path: str, file_name: str
) -> None:
    """Gemini Flash Lite: images only via image_url; PDF rejected; else text."""
    data = _read_file_bytes(file_path, file_name)
    if data == b"":
        raise Exception(f"Cannot read file: {file_name}")

    mime = _mime_for_filename(file_name)
    detected = image_mime_from_bytes(data)
    if mime.startswith("image/"):
        if detected is None:
            raise Exception("Image payload is empty or corrupted")
        image_mime: str | None = mime
    else:
        image_mime = detected

    user_content = client._get_user_content()

    if image_mime is not None:
        encoded = base64.b64encode(data).decode("ascii")
        decoded = base64.b64decode(encoded)
        if decoded != data:
            raise Exception(f"Incomplete image payload: {file_name}")
        user_content.append(
            {
                "type": "image_url",
                "image_url": {"url": f"data:{image_mime};base64,{encoded}"},
            }
        )
        return

    if mime == "application/pdf" or data.startswith(b"%PDF"):
        raise Exception(
            "Gemini Flash Lite image_url accepts images only. "
            "Render the PDF page to JPEG or PNG before sending."
        )

    user_content.append(
        {
            "type": "text",
            "text": file_name + ":\n" + data.decode("utf-8", errors="replace"),
        }
    )


def mistral_raw_base64_file(
    client: OpenrouterClient, file_path: str, file_name: str
) -> None:
    """Mistral Large: images as image_url; PDF as type:file (raw base64 + parser)."""
    data = _read_file_bytes(file_path, file_name)
    mime = _mime_for_filename(file_name)
    detected = image_mime_from_bytes(data)
    user_content = client._get_user_content()

    if mime.startswith("image/") or detected is not None:
        image_mime = detected or mime
        data_url = (
            f"data:{image_mime};base64," + base64.b64encode(data).decode("ascii")
        )
        user_content.append({"type": "image_url", "image_url": {"url": data_url}})
        return

    if Path(file_path).suffix.lower() == ".pdf" or mime == "application/pdf":
        add_plugin = getattr(client, "add_plugin", None)
        if callable(add_plugin):
            add_plugin("file-parser")
        else:
            enable_pdf_parser(client, "pdf-text")
        file_data = base64.b64encode(data).decode("ascii")
        user_content.append(
            {
                "type": "file",
                "file": {"filename": file_name, "file_data": file_data},
            }
        )
        return

    user_content.append(
        {
            "type": "text",
            "text": file_name + ":\n" + data.decode("utf-8", errors="replace"),
        }
    )
