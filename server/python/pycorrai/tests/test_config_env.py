"""Tests for shared .env loading."""

from __future__ import annotations

import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from pycorrai import config


class ParseEnvLineTest(unittest.TestCase):
    def test_simple(self) -> None:
        self.assertEqual(config.parse_env_line("FOO=bar"), ("FOO", "bar"))

    def test_quoted_keeps_hash(self) -> None:
        self.assertEqual(config.parse_env_line('FOO="bar#baz"'), ("FOO", "bar#baz"))

    def test_unquoted_strips_comment(self) -> None:
        self.assertEqual(config.parse_env_line("FOO=bar # comment"), ("FOO", "bar"))

    def test_comment_and_blank(self) -> None:
        self.assertIsNone(config.parse_env_line("# hi"))
        self.assertIsNone(config.parse_env_line(""))


class LoadDotenvTest(unittest.TestCase):
    def setUp(self) -> None:
        config.reset_env_loaded_for_tests()

    def tearDown(self) -> None:
        config.reset_env_loaded_for_tests()

    def test_does_not_overwrite_existing(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / ".env"
            path.write_text("CORRAI_TEST_KEY=from_file\n", encoding="utf-8")
            os.environ["CORRAI_TEST_KEY"] = "from_process"
            try:
                config.load_dotenv(path, overwrite=False)
                self.assertEqual(os.environ["CORRAI_TEST_KEY"], "from_process")
            finally:
                os.environ.pop("CORRAI_TEST_KEY", None)

    def test_fills_missing_keys(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / ".env"
            path.write_text("CORRAI_TEST_FILL=filled\n", encoding="utf-8")
            os.environ.pop("CORRAI_TEST_FILL", None)
            try:
                config.load_dotenv(path, overwrite=False)
                self.assertEqual(os.environ["CORRAI_TEST_FILL"], "filled")
            finally:
                os.environ.pop("CORRAI_TEST_FILL", None)

    def test_default_env_path_points_at_php(self) -> None:
        path = config.default_env_path()
        self.assertTrue(str(path).endswith("server/php/.env"))


class EnvHelperTest(unittest.TestCase):
    def test_env_returns_default(self) -> None:
        with patch.object(config, "_loaded", True):
            self.assertEqual(config.env("CORRAI_MISSING_XYZ", "d"), "d")


if __name__ == "__main__":
    unittest.main()
