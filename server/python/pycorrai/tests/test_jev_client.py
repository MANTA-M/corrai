"""Tests for OpenRouter Jev Decisions client."""

from __future__ import annotations

import unittest
from unittest.mock import patch

from pycorrai.clients.openrouter.jev_client import (
    ChoiceAnswer,
    JevClient,
    NoulAnswer,
    ScoreAnswer,
)
from pycorrai.http.rest import AIError, HttpError


class JevClientPayloadTest(unittest.TestCase):
    def test_default_model(self) -> None:
        client = JevClient()
        self.assertEqual(client.model, "~typesafe/jev-latest")

    def test_build_payload(self) -> None:
        client = JevClient()
        client.set_state(
            {
                "customer_tier": "enterprise",
                "ticket": "My checkout page shows a blank screen after I click Pay.",
            }
        )
        client.add_noul(
            "is_bug",
            "Is the customer reporting a software defect?",
            criteria_true="Broken or unexpected product behavior.",
            criteria_false="Question or feature request.",
        )
        client.add_choice(
            "team",
            "Which team should own this ticket?",
            {
                "payments": "Checkout, billing, or payment processing issues.",
                "frontend": "Rendering, layout, or browser compatibility issues.",
                "account": "Login, permissions, or profile issues.",
            },
        )
        client.add_score(
            "urgency",
            "How urgent is this ticket?",
            [
                "Can wait for the next release",
                "Should be fixed this week",
                "Blocking revenue right now",
            ],
        )
        payload = client.build_payload()
        self.assertEqual(payload["model"], "~typesafe/jev-latest")
        self.assertEqual(payload["questions"]["is_bug"]["type"], "noul")
        self.assertEqual(payload["questions"]["team"]["type"], "choice")
        self.assertEqual(payload["questions"]["urgency"]["type"], "score")
        self.assertEqual(
            payload["questions"]["urgency"]["criteria"][2],
            "Blocking revenue right now",
        )

    def test_requires_state_and_questions(self) -> None:
        client = JevClient()
        with self.assertRaises(ValueError):
            client.build_payload()
        client.set_state("hello")
        with self.assertRaises(ValueError):
            client.build_payload()


class JevClientResponseTest(unittest.TestCase):
    SAMPLE = {
        "id": "gen-dec-test",
        "model": "typesafe/jev-1.13-20260917",
        "provider": "TypeSafe",
        "answers": {
            "is_bug": {"type": "noul", "noul": 0.96},
            "team": {
                "type": "choice",
                "choice": "payments",
                "confidence": 0.67,
                "probabilities": {"payments": 0.78, "frontend": 0.22, "account": 0},
            },
            "urgency": {
                "type": "score",
                "score": 1.99,
                "confidence": 0.99,
                "probabilities": {"0": 0, "1": 0, "2": 1},
                "legend": {
                    "0": "Can wait for the next release",
                    "1": "Should be fixed this week",
                    "2": "Blocking revenue right now",
                },
            },
        },
        "usage": {"input_tokens": 476, "output_tokens": 70, "cost": 0.000019992},
    }

    def test_parse_response(self) -> None:
        response = JevClient.parse_response(self.SAMPLE)
        self.assertEqual(response.model, "typesafe/jev-1.13-20260917")
        self.assertEqual(response.provider, "TypeSafe")
        self.assertAlmostEqual(response.usage.cost, 0.000019992)

        is_bug = response.noul("is_bug")
        self.assertIsInstance(is_bug, NoulAnswer)
        self.assertAlmostEqual(is_bug.noul, 0.96)

        team = response.choice("team")
        self.assertIsInstance(team, ChoiceAnswer)
        self.assertEqual(team.choice, "payments")
        self.assertAlmostEqual(team.probabilities["payments"], 0.78)
        self.assertAlmostEqual(team.confidence or 0.0, 0.67)

        urgency = response.score("urgency")
        self.assertIsInstance(urgency, ScoreAnswer)
        self.assertAlmostEqual(urgency.score, 1.99)
        self.assertEqual(urgency.legend["2"], "Blocking revenue right now")

    def test_typed_accessors_reject_wrong_type(self) -> None:
        response = JevClient.parse_response(self.SAMPLE)
        with self.assertRaises(TypeError):
            response.choice("is_bug")
        with self.assertRaises(TypeError):
            response.noul("urgency")

    def test_decide_uses_query(self) -> None:
        client = JevClient()
        client.set_state({"ticket": "blank screen"})
        client.add_noul("is_bug", "Is this a defect?")
        with patch.object(client, "query_array", return_value=self.SAMPLE) as mocked:
            response = client.decide()
        mocked.assert_called_once()
        self.assertAlmostEqual(response.noul("is_bug").noul, 0.96)
        self.assertEqual(response.choice("team").choice, "payments")

    def test_decide_with_cost(self) -> None:
        client = JevClient()
        client.set_state("x")
        client.add_noul("q", "yes?")
        with patch.object(client, "query_array", return_value=self.SAMPLE):
            _, cost = client.decide_with_cost()
        self.assertAlmostEqual(cost, 0.000019992)

    def test_402_raises_ai_error(self) -> None:
        client = JevClient()
        client.set_state("x")
        client.add_noul("q", "yes?")
        with patch.object(client, "query_array", side_effect=HttpError("pay", 402)):
            with self.assertRaises(AIError):
                client.decide()


if __name__ == "__main__":
    unittest.main()
