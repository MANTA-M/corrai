"""Static S3/Redis path and key builders for the object store."""

from __future__ import annotations

from typing import Any

ATTR_FILE = "attributes.json"
CONTENT_FILE = "content"
OCR_RESULT_FILE = "ocr_result.json"
FOUND_ERRORS_FILE = "found_errors.json"
MARKUP_ANNOTATIONS_FILE = "markup_annotations.php"
THUMBNAIL_FILE = "thumbnail"


class ObjectStoreKeys:
    """Path/key builders matching PHP ``Corrai\\Utils\\Store\\ObjectStore``."""

    ATTR_FILE = ATTR_FILE
    CONTENT_FILE = CONTENT_FILE
    OCR_RESULT_FILE = OCR_RESULT_FILE
    FOUND_ERRORS_FILE = FOUND_ERRORS_FILE
    MARKUP_ANNOTATIONS_FILE = MARKUP_ANNOTATIONS_FILE
    THUMBNAIL_FILE = THUMBNAIL_FILE

    @staticmethod
    def id_index_key(hash_id: str) -> str:
        return "_id/" + hash_id

    @staticmethod
    def schools_root_prefix() -> str:
        return "schools/"

    @staticmethod
    def school_prefix(school_id: str) -> str:
        return f"schools/{school_id}/"

    @staticmethod
    def school_attr_key(school_id: str) -> str:
        return ObjectStoreKeys.school_prefix(school_id) + ATTR_FILE

    @staticmethod
    def teachers_prefix(school_id: str) -> str:
        return ObjectStoreKeys.school_prefix(school_id) + "teachers/"

    @staticmethod
    def teacher_prefix(school_id: str, teacher_id: str) -> str:
        return ObjectStoreKeys.teachers_prefix(school_id) + teacher_id + "/"

    @staticmethod
    def teacher_attr_key(school_id: str, teacher_id: str) -> str:
        return ObjectStoreKeys.teacher_prefix(school_id, teacher_id) + ATTR_FILE

    @staticmethod
    def assessments_prefix(school_id: str, teacher_id: str) -> str:
        return ObjectStoreKeys.teacher_prefix(school_id, teacher_id) + "assessments/"

    @staticmethod
    def assessment_prefix(school_id: str, teacher_id: str, assessment_id: str) -> str:
        return (
            ObjectStoreKeys.assessments_prefix(school_id, teacher_id)
            + assessment_id
            + "/"
        )

    @staticmethod
    def assessment_attr_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_prefix(school_id, teacher_id, assessment_id)
            + ATTR_FILE
        )

    @staticmethod
    def assessment_events_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_prefix(school_id, teacher_id, assessment_id)
            + "events/"
        )

    @staticmethod
    def assessment_event_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        event_id: str,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_events_prefix(
                school_id, teacher_id, assessment_id
            )
            + event_id
            + ".json"
        )

    @staticmethod
    def assessment_files_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_prefix(school_id, teacher_id, assessment_id)
            + "files/"
        )

    @staticmethod
    def assessment_subject_files_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_prefix(school_id, teacher_id, assessment_id)
            + "subject/"
        )

    @staticmethod
    def assessment_subject_compile_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "compiled_subject.json"
        )

    @staticmethod
    def assessment_subject_compile_legacy_json_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        """Previous ``compile_subject.json`` name; migrated by ``compile_subject``."""
        return (
            ObjectStoreKeys.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "compile_subject.json"
        )

    @staticmethod
    def assessment_subject_compile_legacy_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        """Pre-.json key; migrated by ``Assessment.compile_subject``."""
        return (
            ObjectStoreKeys.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "compile_subject"
        )

    @staticmethod
    def assessment_subject_correction_grid_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "correction_grid.json"
        )

    @staticmethod
    def assessment_subject_strict_correction_grid_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "strict_correction_grid.json"
        )

    @staticmethod
    def assessment_subject_math_correction_grid_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_subject_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + "math_correction_grid.json"
        )

    @staticmethod
    def assessment_unclassified_files_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_prefix(school_id, teacher_id, assessment_id)
            + "unclassified/"
        )

    @staticmethod
    def assessment_file_area(type_: str, student_id: str | None) -> str:
        if student_id is not None and student_id.strip() != "":
            return "student"
        if type_ in ("subject", "solution", "instructions"):
            return "subject"
        return "unclassified"

    @staticmethod
    def assessment_file_prefix(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        area = ObjectStoreKeys.assessment_file_area(type_, student_id)
        if area == "student":
            return (
                ObjectStoreKeys.assessment_student_prefix(
                    school_id, teacher_id, assessment_id, (student_id or "").strip()
                )
                + file_id
                + "/"
            )
        if area == "subject":
            return (
                ObjectStoreKeys.assessment_subject_files_prefix(
                    school_id, teacher_id, assessment_id
                )
                + file_id
                + "/"
            )
        return (
            ObjectStoreKeys.assessment_unclassified_files_prefix(
                school_id, teacher_id, assessment_id
            )
            + file_id
            + "/"
        )

    @staticmethod
    def assessment_blobs_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_prefix(school_id, teacher_id, assessment_id)
            + "blobs/"
        )

    @staticmethod
    def assessment_blob_key(
        school_id: str, teacher_id: str, assessment_id: str, file_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_blobs_prefix(
                school_id, teacher_id, assessment_id
            )
            + file_id
        )

    @staticmethod
    def assessment_file_attr_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + ATTR_FILE
        )

    @staticmethod
    def assessment_file_content_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + CONTENT_FILE
        )

    @staticmethod
    def assessment_file_ocr_result_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + OCR_RESULT_FILE
        )

    @staticmethod
    def assessment_file_found_errors_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + FOUND_ERRORS_FILE
        )

    @staticmethod
    def assessment_file_markup_annotations_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + MARKUP_ANNOTATIONS_FILE
        )

    @staticmethod
    def assessment_file_thumbnail_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + THUMBNAIL_FILE
        )

    @staticmethod
    def assessment_file_events_prefix(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_file_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + "events/"
        )

    @staticmethod
    def assessment_file_event_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        file_id: str,
        event_id: str,
        type_: str = "",
        student_id: str | None = None,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_file_events_prefix(
                school_id, teacher_id, assessment_id, file_id, type_, student_id
            )
            + event_id
            + ".json"
        )

    @staticmethod
    def assessment_students_prefix(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_prefix(school_id, teacher_id, assessment_id)
            + "students/"
        )

    @staticmethod
    def assessment_students_list_key(
        school_id: str, teacher_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_students_prefix(
                school_id, teacher_id, assessment_id
            )
            + "list.json"
        )

    @staticmethod
    def assessment_student_prefix(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_students_prefix(
                school_id, teacher_id, assessment_id
            )
            + student_id
            + "/"
        )

    @staticmethod
    def assessment_student_attr_key(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + ATTR_FILE
        )

    @staticmethod
    def assessment_student_correction_key(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + "correction.png"
        )

    @staticmethod
    def assessment_student_compiled_submission_key(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + "compiled_submission.json"
        )

    @staticmethod
    def assessment_student_events_prefix(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + "events/"
        )

    @staticmethod
    def assessment_student_event_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        student_id: str,
        event_id: str,
    ) -> str:
        return (
            ObjectStoreKeys.assessment_student_events_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + event_id
            + ".json"
        )

    @staticmethod
    def assessment_student_correction_json_key(
        school_id: str, teacher_id: str, assessment_id: str, student_id: str
    ) -> str:
        return (
            ObjectStoreKeys.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + "correction.json"
        )

    @staticmethod
    def assessment_student_correction_model_json_key(
        school_id: str,
        teacher_id: str,
        assessment_id: str,
        student_id: str,
        model_name: str,
    ) -> str:
        """``correction_<model>.json`` for a student (model slashes → ``_``)."""
        safe = (
            model_name.strip().replace("/", "_").replace("\\", "_").replace(" ", "_")
        )
        if safe == "":
            safe = "model"
        return (
            ObjectStoreKeys.assessment_student_prefix(
                school_id, teacher_id, assessment_id, student_id
            )
            + f"correction_{safe}.json"
        )

    @staticmethod
    def parse_node_prefix(prefix: str) -> dict[str, Any]:
        """Parse a schools/... node prefix into path segments."""
        parts = [p for p in prefix.strip("/").split("/") if p != ""]
        if len(parts) < 2 or parts[0] != "schools":
            raise ValueError(f"Invalid node prefix: {prefix}")

        school_id = parts[1]
        if len(parts) == 2:
            return {"kind": "school", "school_id": school_id}

        if (parts[2] if len(parts) > 2 else "") != "teachers" or len(parts) < 4:
            raise ValueError(f"Invalid teacher path in prefix: {prefix}")
        teacher_id = parts[3]
        if len(parts) == 4:
            return {
                "kind": "teacher",
                "school_id": school_id,
                "teacher_id": teacher_id,
            }

        if (parts[4] if len(parts) > 4 else "") != "assessments" or len(parts) < 6:
            raise ValueError(f"Invalid assessment path in prefix: {prefix}")
        assessment_id = parts[5]
        if len(parts) == 6:
            return {
                "kind": "assessment",
                "school_id": school_id,
                "teacher_id": teacher_id,
                "assessment_id": assessment_id,
            }

        area = parts[6] if len(parts) > 6 else ""
        if area == "blobs" and len(parts) == 8:
            return {
                "kind": "blob",
                "school_id": school_id,
                "teacher_id": teacher_id,
                "assessment_id": assessment_id,
                "file_id": parts[7],
                "area": "blobs",
            }

        if area in ("subject", "unclassified", "files") and len(parts) == 8:
            return {
                "kind": "file",
                "school_id": school_id,
                "teacher_id": teacher_id,
                "assessment_id": assessment_id,
                "file_id": parts[7],
                "area": "unclassified" if area == "files" else area,
            }

        if area == "students" and len(parts) >= 8:
            if len(parts) == 8:
                return {
                    "kind": "student",
                    "school_id": school_id,
                    "teacher_id": teacher_id,
                    "assessment_id": assessment_id,
                    "student_id": parts[7],
                }
            if len(parts) == 9:
                if parts[8] == "correction.png" or "." in parts[8]:
                    return {
                        "kind": "blob",
                        "school_id": school_id,
                        "teacher_id": teacher_id,
                        "assessment_id": assessment_id,
                        "student_id": parts[7],
                        "file_id": parts[8],
                        "area": "student",
                    }
                return {
                    "kind": "file",
                    "school_id": school_id,
                    "teacher_id": teacher_id,
                    "assessment_id": assessment_id,
                    "student_id": parts[7],
                    "file_id": parts[8],
                    "area": "student",
                }

        raise ValueError(f"Unrecognized node prefix: {prefix}")

    # -------------------------------------------------------------------------
    # Legacy CSV path builders
    # -------------------------------------------------------------------------

    @staticmethod
    def legacy_school_prefix(school_id: str) -> str:
        return school_id + "/"

    @staticmethod
    def legacy_school_csv_key(school_id: str) -> str:
        return school_id + "/school.csv"

    @staticmethod
    def legacy_user_prefix(school_id: str, user_id: str) -> str:
        return f"{school_id}/{user_id}/"

    @staticmethod
    def legacy_user_csv_key(school_id: str, user_id: str) -> str:
        return f"{school_id}/{user_id}/user.csv"

    @staticmethod
    def legacy_assessment_prefix(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return f"{school_id}/{user_id}/{assessment_id}/"

    @staticmethod
    def legacy_assessment_csv_key(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return f"{school_id}/{user_id}/{assessment_id}/exam.csv"

    @staticmethod
    def legacy_assessment_files_csv_key(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.legacy_assessment_prefix(
                school_id, user_id, assessment_id
            )
            + "files.csv"
        )

    @staticmethod
    def legacy_assessment_files_prefix(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.legacy_assessment_prefix(
                school_id, user_id, assessment_id
            )
            + "files/"
        )

    @staticmethod
    def legacy_assessment_file_key(
        school_id: str, user_id: str, assessment_id: str, filename: str
    ) -> str:
        return (
            ObjectStoreKeys.legacy_assessment_files_prefix(
                school_id, user_id, assessment_id
            )
            + filename
        )

    @staticmethod
    def legacy_assessment_unassigned_prefix(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.legacy_assessment_prefix(
                school_id, user_id, assessment_id
            )
            + "unassigned/"
        )

    @staticmethod
    def legacy_assessment_subject_prefix(
        school_id: str, user_id: str, assessment_id: str
    ) -> str:
        return (
            ObjectStoreKeys.legacy_assessment_prefix(
                school_id, user_id, assessment_id
            )
            + "subject/"
        )
