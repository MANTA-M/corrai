"""Subject material given to students."""

from __future__ import annotations

from pycorrai.model.input_file import InputFile


class SubjectFile(InputFile):
    def on_stored(self) -> None:
        return
