"""Short alphanumeric identifiers used as S3 tree node names."""

from __future__ import annotations

import re
import secrets

from pycorrai.store.object_store import ObjectStore

LENGTH = 7
_CHARS = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"
_VALID = re.compile(rf"^[0-9a-zA-Z]{{{LENGTH}}}$")


class HashId:
    LENGTH = LENGTH

    @staticmethod
    def create(max_attempts: int = 1000) -> str:
        """Generate a unique 7-character id that is not already in ``_id/``."""
        store = ObjectStore.get_instance()
        attempts = 0
        while True:
            hash_id = "".join(secrets.choice(_CHARS) for _ in range(LENGTH))
            attempts += 1
            if attempts >= max_attempts:
                raise RuntimeError(
                    f"Could not generate unique ID after {max_attempts} attempts"
                )
            if not store.exists(ObjectStore.id_index_key(hash_id)):
                return hash_id

    @staticmethod
    def is_valid(id_: str) -> bool:
        return bool(_VALID.match(id_))
