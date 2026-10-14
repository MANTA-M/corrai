"""Image downscale mirroring PHP ImageRedimentioner."""

from __future__ import annotations

import io
import logging
import mimetypes
from pathlib import Path

from PIL import Image

logger = logging.getLogger(__name__)

_EXT_MIME = {
    ".pdf": "application/pdf",
    ".png": "image/png",
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".gif": "image/gif",
    ".webp": "image/webp",
    ".bmp": "image/bmp",
    ".tif": "image/tiff",
    ".tiff": "image/tiff",
    ".heic": "image/heic",
    ".txt": "text/plain",
    ".json": "application/json",
    ".md": "text/markdown",
}


def mime_type_for_filename(filename: str) -> str:
    ext = Path(filename).suffix.lower()
    if ext in _EXT_MIME:
        return _EXT_MIME[ext]
    guessed, _ = mimetypes.guess_type(filename)
    return guessed or "application/octet-stream"


def image_mime_from_bytes(data: bytes) -> str | None:
    try:
        with Image.open(io.BytesIO(data)) as img:
            fmt = (img.format or "").upper()
    except Exception:
        return None
    mapping = {
        "JPEG": "image/jpeg",
        "PNG": "image/png",
        "GIF": "image/gif",
        "WEBP": "image/webp",
        "BMP": "image/bmp",
        "TIFF": "image/tiff",
    }
    return mapping.get(fmt)


class ImageRedimensioner:
    """Resize image bytes so the longest side is at most ``max_dimension``."""

    def __init__(self, max_dimension: int, data: bytes):
        if max_dimension <= 0:
            raise ValueError(f"Max dimension must be greater than 0, got {max_dimension}")
        if not data:
            raise ValueError("Image bytes must not be empty")

        with Image.open(io.BytesIO(data)) as img:
            width, height = img.size
            if width <= 0 or height <= 0:
                raise ValueError("Image dimensions must be greater than 0")
            if width <= max_dimension and height <= max_dimension:
                self.image = data
                self.factor = 1.0
                logger.info(
                    "[ImageRedimensioner] scaling factor: %s (source %dx%d)",
                    self.factor,
                    width,
                    height,
                )
                return

            long_side = max(width, height)
            scale = max_dimension / long_side
            if width >= height:
                new_width = max_dimension
                new_height = max(1, round(height * scale))
            else:
                new_height = max_dimension
                new_width = max(1, round(width * scale))

            fmt = (img.format or "JPEG").upper()
            resampled = img.convert("RGBA") if img.mode in ("P", "LA") else img.copy()
            resampled = resampled.resize((new_width, new_height), Image.Resampling.LANCZOS)

            buf = io.BytesIO()
            save_fmt = fmt if fmt in {"JPEG", "PNG", "GIF", "WEBP", "BMP"} else "JPEG"
            if save_fmt == "JPEG" and resampled.mode in ("RGBA", "P"):
                resampled = resampled.convert("RGB")
            save_kwargs: dict = {"quality": 90} if save_fmt in {"JPEG", "WEBP"} else {}
            if save_fmt == "PNG":
                save_kwargs["optimize"] = True
            resampled.save(buf, format=save_fmt, **save_kwargs)
            self.image = buf.getvalue()
            self.factor = (new_width if width >= height else new_height) / long_side
            logger.info(
                "[ImageRedimensioner] scaling factor: %s (source %dx%d)",
                self.factor,
                width,
                height,
            )


# Alias matching PHP typo spelling used in docs/comments.
ImageRedimentioner = ImageRedimensioner
