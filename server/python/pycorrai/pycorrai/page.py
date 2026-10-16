"""Page zone detection (compat re-export). Prefer ``pycorrai.image.page``."""

from pycorrai.image.page import (
    PageDetection,
    PageZone,
    classify_page_orientation,
    crop_document,
    detect_page,
    find_page_zone,
    get_orientation_classifier,
    image_from_bytes,
    read_image,
)

__all__ = [
    "PageDetection",
    "PageZone",
    "classify_page_orientation",
    "crop_document",
    "detect_page",
    "find_page_zone",
    "get_orientation_classifier",
    "image_from_bytes",
    "read_image",
]
