"""Student copy (submission)."""

from __future__ import annotations

from pycorrai.model.input_file import InputFile


class SubmissionFile(InputFile):
    def __init__(self) -> None:
        super().__init__()
        self.student_identifier: str | None = None
        self.qualigraphy_score: int | float | None = None

    def can_reassign(self) -> bool:
        return True

    def on_stored(self) -> None:
        return

    def on_correction_asked(self) -> None:
        return
