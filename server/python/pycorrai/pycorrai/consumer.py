"""Redis consumer that OCRs assessment files from S3 and hands them to PHP.

Implementation lives in ``pycorrai.worker``; this module re-exports the public
API so ``python -m pycorrai.consumer`` and existing imports keep working.
"""

from __future__ import annotations

from pycorrai.ocr.dispatch import DEFAULT_ENGINE, ENGINES, recognize_bytes
from pycorrai.store.metadata_cache import (
    assessment_key as metadata_cache_assessment_key,
    invalidate_for_path,
)
from pycorrai.store.object_store import (
    BRPOP_TIMEOUT,
    SCHEMA,
    SOCKET_TIMEOUT,
    ObjectStore,
    make_redis_client,
    make_s3_client,
)
from pycorrai.worker import (
    DEFAULT_LANG,
    DEFAULT_OCR_ENGINE,
    OCR_LIST_KEY,
    PHP_LIST_KEY,
    STATUS_LABELS,
    assessment_id_from_path,
    attributes_key,
    enqueue_assessment_task,
    event_body,
    event_key,
    file_id_from_path,
    file_prefix,
    format_ocr_json,
    invalidate_metadata_cache,
    main,
    ocr_json_key,
    parse_queue_message,
    parse_ticket,
    php_task_ticket,
    publish_assessment_status,
    run_forever,
    school_teacher_assessment_from_path,
    treat,
    treat_assessment_task,
)

__all__ = [
    "BRPOP_TIMEOUT",
    "DEFAULT_ENGINE",
    "DEFAULT_LANG",
    "DEFAULT_OCR_ENGINE",
    "ENGINES",
    "OCR_LIST_KEY",
    "PHP_LIST_KEY",
    "SCHEMA",
    "SOCKET_TIMEOUT",
    "STATUS_LABELS",
    "ObjectStore",
    "assessment_id_from_path",
    "attributes_key",
    "enqueue_assessment_task",
    "event_body",
    "event_key",
    "file_id_from_path",
    "file_prefix",
    "format_ocr_json",
    "invalidate_for_path",
    "invalidate_metadata_cache",
    "main",
    "make_redis_client",
    "make_s3_client",
    "metadata_cache_assessment_key",
    "ocr_json_key",
    "parse_queue_message",
    "parse_ticket",
    "php_task_ticket",
    "publish_assessment_status",
    "recognize_bytes",
    "run_forever",
    "school_teacher_assessment_from_path",
    "treat",
    "treat_assessment_task",
]


if __name__ == "__main__":
    raise SystemExit(main())
