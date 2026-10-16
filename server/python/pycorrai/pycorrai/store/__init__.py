"""S3 object store and related helpers for Corrai workers."""

from pycorrai.store.exceptions import StoreConflictException
from pycorrai.store.hash_id import HashId
from pycorrai.store.metadata_cache import (
    assessment_gen_key,
    assessment_key,
    invalidate,
    invalidate_assessment,
    invalidate_for_path,
)
from pycorrai.store.keys import ObjectStoreKeys
from pycorrai.store.object_store import (
    ATTR_FILE,
    CONTENT_FILE,
    FOUND_ERRORS_FILE,
    MARKUP_ANNOTATIONS_FILE,
    OCR_RESULT_FILE,
    SCHEMA,
    THUMBNAIL_FILE,
    ObjectStore,
    make_redis_client,
    make_s3_client,
)

__all__ = [
    "ATTR_FILE",
    "CONTENT_FILE",
    "FOUND_ERRORS_FILE",
    "HashId",
    "MARKUP_ANNOTATIONS_FILE",
    "OCR_RESULT_FILE",
    "ObjectStore",
    "ObjectStoreKeys",
    "SCHEMA",
    "StoreConflictException",
    "THUMBNAIL_FILE",
    "assessment_gen_key",
    "assessment_key",
    "invalidate",
    "invalidate_assessment",
    "invalidate_for_path",
    "make_redis_client",
    "make_s3_client",
]
