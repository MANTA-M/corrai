"""Long-running Redis BRPOP loop for the Python worker."""

from __future__ import annotations

import logging
import sys

from redis import Redis
from redis.exceptions import TimeoutError as RedisTimeoutError

from pycorrai.config import ensure_env_loaded
from pycorrai.ocr.dispatch import recognize_bytes
from pycorrai.store.object_store import (
    BRPOP_TIMEOUT,
    ObjectStore,
    make_redis_client,
)
from pycorrai.worker.ocr_job import treat
from pycorrai.worker.tasks import treat_assessment_task
from pycorrai.worker.tickets import OCR_LIST_KEY, parse_queue_message

logger = logging.getLogger(__name__)


def run_forever(
    store: ObjectStore | None = None,
    redis: Redis | None = None,
    recognize=recognize_bytes,
) -> None:
    store = store if store is not None else ObjectStore()
    redis = redis if redis is not None else make_redis_client()

    # OCR models are loaded lazily on the first ticket for each engine so this
    # process does not pull both Paddle and docTR stacks at startup.
    logger.info("Python consumer started, waiting on %s", OCR_LIST_KEY)
    while True:
        try:
            result = redis.brpop(OCR_LIST_KEY, timeout=BRPOP_TIMEOUT)
        except RedisTimeoutError:
            logger.warning(
                "Redis read timed out while waiting on %s; retrying",
                OCR_LIST_KEY,
            )
            continue
        except Exception:
            logger.exception("Python consumer error")
            continue

        if result is None:
            continue
        try:
            _key, raw = result
            ticket = parse_queue_message(raw)
            if ticket.get("kind") == "task":
                logger.info(
                    "Treating task ticket task=%s assessment=%s",
                    ticket["task"],
                    ticket["assessment_id"],
                )
                treat_assessment_task(
                    ticket["task"],
                    ticket["assessment_id"],
                    student_ids=ticket.get("student_ids"),
                    model=ticket.get("model"),
                )
                continue
            logger.info(
                "Treating OCR ticket %s engine=%s",
                ticket["path"],
                ticket["engine"],
            )
            treat(
                ticket["path"],
                ticket["lang"],
                store,
                redis,
                recognize=recognize,
                after_task=ticket["after_task"],
                engine=ticket["engine"],
            )
        except Exception:
            logger.exception("Python consumer error")


def main(argv: list[str] | None = None) -> int:
    del argv  # CLI takes no arguments; reserved for future flags.
    ensure_env_loaded()
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        stream=sys.stderr,
    )
    run_forever()
    return 0
