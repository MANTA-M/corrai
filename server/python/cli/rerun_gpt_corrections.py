#!/usr/bin/env python3
"""Correct students 482–492 with openai/gpt-6.1-sol and save correction_gpt.json.

Usage (from repo root or this directory):
  ../.venv/bin/python rerun_gpt_corrections.py
"""

from __future__ import annotations

import json
import sys
from datetime import datetime
from pathlib import Path

# Allow importing pycorrai when run as a standalone script.
_PYCORRAI_ROOT = Path(__file__).resolve().parents[1] / "pycorrai"
if str(_PYCORRAI_ROOT) not in sys.path:
    sys.path.insert(0, str(_PYCORRAI_ROOT))

from pycorrai.config import ensure_env_loaded  # noqa: E402
from pycorrai.model.assessment import Assessment  # noqa: E402
from pycorrai.model.student import Student  # noqa: E402
from pycorrai.store.object_store import ObjectStore  # noqa: E402

STUDENTS: dict[str, str] = {
    "482": "BdyohQN",
    "483": "4vvAZt3",
    "484": "Zgganah",
    "485": "9NlnMPr",
    "486": "Op5WN2y",
    "487": "87oQCA5",
    "488": "HXoOZwW",
    "489": "oJ7Bq4h",
    "490": "SgyvVq6",
    "491": "EeAP30C",
    "492": "TCcmv5V",
}

ASSESSMENT_ID = "IdC3gkB"
SCHOOL_ID = "IND"
TEACHER_ID = "pQHeAXW"
MODEL_ID = "openai/gpt-6.1-sol"

OUT_ROOT = Path(__file__).resolve().parents[3] / "doc" / "test_files"


def _ts() -> str:
    return datetime.now().strftime("%H:%M:%S")


def main() -> int:
    ensure_env_loaded()
    store = ObjectStore.get_instance()
    assessment = Assessment.from_hash(ASSESSMENT_ID)

    if (
        assessment.school_id != SCHOOL_ID
        or assessment.user_id != TEACHER_ID
        or assessment.id != ASSESSMENT_ID
    ):
        print(
            f"{_ts()} ERROR: unexpected assessment path "
            f"{assessment.school_id}/{assessment.user_id}/{assessment.id}",
            file=sys.stderr,
        )
        return 1

    id_to_label = {sid: label for label, sid in STUDENTS.items()}
    students: list[Student] = []
    for label, student_id in STUDENTS.items():
        print(f"{_ts()} loading student {label} ({student_id})")
        students.append(Student.from_hash(student_id))

    print(f"{_ts()} correcting {len(students)} students with {MODEL_ID}")
    marks_by_id = assessment.correct_students(students, MODEL_ID)

    marks_by_label: dict[str, float | None] = {}
    ok = 0
    fail = 0

    for student_id, mark in marks_by_id.items():
        label = id_to_label.get(student_id, student_id)
        marks_by_label[label] = mark

        dest_dir = OUT_ROOT / label
        dest_dir.mkdir(parents=True, exist_ok=True)
        dest = dest_dir / "correction_gpt.json"
        correction_key = ObjectStore.assessment_student_correction_json_key(
            SCHOOL_ID, TEACHER_ID, ASSESSMENT_ID, student_id
        )

        if mark is None or not store.exists(correction_key):
            print(f"{_ts()} {label} => FAILED (no correction.json)")
            fail += 1
            continue

        contents = store.get_contents(correction_key)
        dest.write_text(contents, encoding="utf-8")
        print(f"{_ts()} {label} => {mark}  saved {dest} ({len(contents)} bytes)")
        ok += 1

    print()
    print("Notes:")
    for label in sorted(marks_by_label, key=lambda x: int(x) if x.isdigit() else x):
        mark = marks_by_label[label]
        display = "None" if mark is None else mark
        print(f'  "{label}" => {display}')

    print()
    print(json.dumps(marks_by_label, ensure_ascii=False, indent=2, sort_keys=True))
    print(f"{_ts()} DONE ok={ok} fail={fail}")
    return 1 if fail > 0 else 0


if __name__ == "__main__":
    raise SystemExit(main())
