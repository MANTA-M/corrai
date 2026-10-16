#!/usr/bin/env python3
"""Delete all schools except the Independent (IND) school.

Mirrors PHP School::delete() cascade: assessments (and their file/student
id pointers), teacher id pointers, then the school prefix + school id pointer.

Usage:
  python -m pycorrai.scripts.delete_non_ind_schools
  python -m pycorrai.scripts.delete_non_ind_schools --dry-run
"""

from __future__ import annotations

import argparse
import sys
from datetime import datetime
from typing import Any

from pycorrai.config import ensure_env_loaded
from pycorrai.model.assessment import Assessment
from pycorrai.store.object_store import ObjectStore

IND_SCHOOL_ID = "IND"


def _ts() -> str:
    return datetime.now().strftime("%H:%M:%S")


def _school_name(store: ObjectStore, school_id: str) -> str:
    attr_key = ObjectStore.school_attr_key(school_id)
    if not store.exists(attr_key):
        return ""
    try:
        payload = store.get_json(attr_key)
        if not isinstance(payload, dict):
            return ""
        # Python get_json returns the object body; PHP getJson wraps it in "data".
        nested = payload.get("data")
        if isinstance(nested, dict) and "name" in nested:
            return str(nested.get("name") or "")
        return str(payload.get("name") or "")
    except Exception:
        return ""


def _list_schools(store: ObjectStore) -> list[dict[str, str]]:
    schools: list[dict[str, str]] = []
    for school_id in store.list_child_prefixes(ObjectStore.schools_root_prefix()):
        attr_key = ObjectStore.school_attr_key(school_id)
        if not store.exists(attr_key):
            continue
        schools.append(
            {
                "id": school_id,
                "name": _school_name(store, school_id),
            }
        )
    return schools


def _delete_school(store: ObjectStore, school_id: str) -> None:
    """Cascade-delete a school like PHP School::delete()."""
    teachers_prefix = ObjectStore.teachers_prefix(school_id)
    for teacher_id in store.list_child_prefixes(teachers_prefix):
        assessments_prefix = ObjectStore.assessments_prefix(school_id, teacher_id)
        for assessment_id in store.list_child_prefixes(assessments_prefix):
            attr_key = ObjectStore.assessment_attr_key(
                school_id, teacher_id, assessment_id
            )
            if not store.exists(attr_key):
                continue
            try:
                Assessment.from_hash(assessment_id).delete()
            except Exception:
                # Fall back to prefix + pointer cleanup if from_hash fails.
                store.delete_prefix(
                    ObjectStore.assessment_prefix(school_id, teacher_id, assessment_id)
                )
                store.delete_id_pointer(assessment_id)
        store.delete_id_pointer(teacher_id)

    store.delete_prefix(ObjectStore.school_prefix(school_id))
    store.delete_id_pointer(school_id)


def delete_non_ind_schools(*, dry_run: bool = False) -> dict[str, Any]:
    store = ObjectStore.get_instance()
    all_schools = _list_schools(store)
    targets = [s for s in all_schools if s["id"] != IND_SCHOOL_ID]

    result: dict[str, Any] = {
        "total_schools": len(all_schools),
        "matched": len(targets),
        "deleted": 0,
        "errors": [],
        "schools": targets,
    }

    if not targets:
        print(f"{_ts()} No non-IND schools found ({len(all_schools)} total).")
        return result

    print(
        f"{_ts()} Found {len(targets)} non-IND school(s) "
        f"out of {len(all_schools)} total."
    )

    if dry_run:
        print(f"{_ts()} DRY-RUN mode enabled. No schools will be deleted.")
        for index, school in enumerate(targets, start=1):
            name = school["name"] or "(unnamed)"
            print(
                f"{_ts()}  [{index}/{len(targets)}] Would delete: "
                f"{name} (id: {school['id']})"
            )
        return result

    for index, school in enumerate(targets, start=1):
        school_id = school["id"]
        name = school["name"] or "(unnamed)"
        try:
            _delete_school(store, school_id)
            result["deleted"] += 1
            print(
                f"{_ts()}  [{index}/{len(targets)}] Deleted: "
                f"{name} (id: {school_id})"
            )
        except Exception as exc:
            error_msg = f"Failed to delete school {school_id} ({name}): {exc}"
            result["errors"].append(error_msg)
            print(f"{_ts()}  [ERROR] {error_msg}", file=sys.stderr)

    errors = result["errors"]
    print(
        f"{_ts()} Completed: {result['deleted']}/{result['matched']} "
        f"school(s) deleted successfully."
        + (f" ({len(errors)} error(s))" if errors else "")
    )
    return result


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Delete all schools except the Independent (IND) school."
    )
    parser.add_argument(
        "-d",
        "--dry-run",
        action="store_true",
        help="List matching schools without deleting them",
    )
    args = parser.parse_args(argv)

    ensure_env_loaded()
    result = delete_non_ind_schools(dry_run=args.dry_run)
    return 1 if result["errors"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
