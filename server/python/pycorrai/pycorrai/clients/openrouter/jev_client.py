"""OpenRouter Jev (TypeSafe) Decisions API client.

Uses ``POST /api/alpha/decisions`` with model ``~typesafe/jev-latest``.
Jev returns typed answers (noul / choice / score) with probabilities,
not free-form chat text. See:
https://openrouter.ai/docs/guides/community/jev-tutorial
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Literal, Mapping, Sequence

from pycorrai.config import ensure_env_loaded, env
from pycorrai.http.rest import AIError, LlmClient, raise_ai_on_402

State = str | Mapping[str, Any] | Sequence[Any]
CriteriaValue = str | Mapping[str, Any] | Sequence[Any] | bool | int | float | None
QuestionType = Literal["noul", "choice", "score"]


@dataclass(frozen=True)
class NoulAnswer:
    noul: float
    type: Literal["noul"] = "noul"

    @property
    def probability_yes(self) -> float:
        return self.noul


@dataclass(frozen=True)
class ChoiceAnswer:
    choice: str
    probabilities: Mapping[str, float] = field(default_factory=dict)
    confidence: float | None = None
    type: Literal["choice"] = "choice"


@dataclass(frozen=True)
class ScoreAnswer:
    score: float
    probabilities: Mapping[str, float] = field(default_factory=dict)
    legend: Mapping[str, Any] = field(default_factory=dict)
    confidence: float | None = None
    type: Literal["score"] = "score"


Answer = NoulAnswer | ChoiceAnswer | ScoreAnswer


@dataclass(frozen=True)
class DecisionsUsage:
    input_tokens: int = 0
    output_tokens: int = 0
    cost: float = 0.0


@dataclass(frozen=True)
class DecisionsResponse:
    answers: Mapping[str, Answer]
    model: str
    usage: DecisionsUsage
    id: str | None = None
    provider: str | None = None
    raw: Mapping[str, Any] = field(default_factory=dict)

    def get(self, key: str) -> Answer:
        try:
            return self.answers[key]
        except KeyError as e:
            raise KeyError(f"No answer for question {key!r}") from e

    def noul(self, key: str) -> NoulAnswer:
        answer = self.get(key)
        if not isinstance(answer, NoulAnswer):
            raise TypeError(f"Answer {key!r} is {answer.type!r}, expected 'noul'")
        return answer

    def choice(self, key: str) -> ChoiceAnswer:
        answer = self.get(key)
        if not isinstance(answer, ChoiceAnswer):
            raise TypeError(f"Answer {key!r} is {answer.type!r}, expected 'choice'")
        return answer

    def score(self, key: str) -> ScoreAnswer:
        answer = self.get(key)
        if not isinstance(answer, ScoreAnswer):
            raise TypeError(f"Answer {key!r} is {answer.type!r}, expected 'score'")
        return answer


class JevClient(LlmClient):
    """Client for TypeSafe Jev via OpenRouter Decisions API."""

    DECISIONS_API_URL = "https://openrouter.ai/api/alpha/decisions"
    DEFAULT_MODEL = "~typesafe/jev-latest"

    def __init__(self, model: str | None = None) -> None:
        ensure_env_loaded()
        super().__init__("", env("OPENROUTER_API_KEY"))
        self.model = model or self.DEFAULT_MODEL
        self.state: State | None = None
        self.questions: dict[str, dict[str, Any]] = {}
        self.session_id: str | None = None
        self.user: str | None = None
        self.trace: Any | None = None
        self.provider: dict[str, Any] | None = None

    def set_state(self, state: State) -> None:
        self.state = state

    def set_questions(self, questions: Mapping[str, Mapping[str, Any]]) -> None:
        self.questions = {key: dict(question) for key, question in questions.items()}

    def clear_questions(self) -> None:
        self.questions.clear()

    def add_noul(
        self,
        key: str,
        instructions: CriteriaValue,
        *,
        criteria_true: CriteriaValue = None,
        criteria_false: CriteriaValue = None,
        criteria: Mapping[str, CriteriaValue] | None = None,
    ) -> None:
        """Add a yes/no (noul) question. Returns P(yes) in ``[0, 1]``."""
        question: dict[str, Any] = {
            "type": "noul",
            "instructions": instructions,
        }
        resolved = self._noul_criteria(criteria, criteria_true, criteria_false)
        if resolved is not None:
            question["criteria"] = resolved
        self.questions[key] = question

    def add_choice(
        self,
        key: str,
        instructions: CriteriaValue,
        criteria: Mapping[str, CriteriaValue],
    ) -> None:
        """Add a multi-option choice question."""
        if not criteria:
            raise ValueError("choice criteria must map at least one option to a description")
        self.questions[key] = {
            "type": "choice",
            "instructions": instructions,
            "criteria": dict(criteria),
        }

    def add_score(
        self,
        key: str,
        instructions: CriteriaValue,
        criteria: Sequence[CriteriaValue],
    ) -> None:
        """Add an ordered score question (lowest level first)."""
        if len(criteria) < 2:
            raise ValueError("score criteria must be an ordered list with at least 2 levels")
        self.questions[key] = {
            "type": "score",
            "instructions": instructions,
            "criteria": list(criteria),
        }

    def build_payload(self) -> dict[str, Any]:
        if self.state is None:
            raise ValueError("state is required before calling decide()")
        if not self.questions:
            raise ValueError("at least one question is required before calling decide()")
        payload: dict[str, Any] = {
            "model": self.model,
            "state": self.state,
            "questions": self.questions,
        }
        if self.session_id is not None:
            payload["session_id"] = self.session_id
        if self.user is not None:
            payload["user"] = self.user
        if self.trace is not None:
            payload["trace"] = self.trace
        if self.provider is not None:
            payload["provider"] = self.provider
        return payload

    def decide(self) -> DecisionsResponse:
        """Send the decisions request and return typed answers."""
        raw = self._query_decisions()
        return self.parse_response(raw)

    def decide_with_cost(self) -> tuple[DecisionsResponse, float]:
        response = self.decide()
        return response, response.usage.cost

    def call(self) -> DecisionsResponse:
        """Alias of :meth:`decide` for consistency with other clients."""
        return self.decide()

    def _query_decisions(self) -> dict[str, Any]:
        try:
            result = self.query_array(
                self.DECISIONS_API_URL,
                "POST",
                self.common_headers,
                self.build_payload(),
            )
            if result is None:
                raise Exception("Empty response from Jev")
            if not isinstance(result, dict):
                raise Exception("Invalid Jev response")
            return result
        except Exception as th:
            raise_ai_on_402(th)
            raise  # pragma: no cover — raise_ai_on_402 always raises

    @staticmethod
    def parse_response(raw: Mapping[str, Any]) -> DecisionsResponse:
        answers_raw = raw.get("answers")
        if not isinstance(answers_raw, dict):
            raise Exception("Invalid Jev response: missing answers")
        model = raw.get("model")
        if not isinstance(model, str) or model == "":
            raise Exception("Invalid Jev response: missing model")

        answers: dict[str, Answer] = {}
        for key, value in answers_raw.items():
            answers[str(key)] = JevClient._parse_answer(str(key), value)

        return DecisionsResponse(
            answers=answers,
            model=model,
            usage=JevClient._parse_usage(raw.get("usage")),
            id=raw.get("id") if isinstance(raw.get("id"), str) else None,
            provider=raw.get("provider") if isinstance(raw.get("provider"), str) else None,
            raw=dict(raw),
        )

    @staticmethod
    def _parse_answer(key: str, value: Any) -> Answer:
        if not isinstance(value, dict):
            raise Exception(f"Invalid answer for {key!r}: expected object")
        answer_type = value.get("type")
        if answer_type == "noul":
            noul = value.get("noul")
            if not isinstance(noul, (int, float)) or isinstance(noul, bool):
                raise Exception(f"Invalid noul answer for {key!r}: missing noul")
            return NoulAnswer(noul=float(noul))
        if answer_type == "choice":
            choice = value.get("choice")
            if not isinstance(choice, str) or choice == "":
                raise Exception(f"Invalid choice answer for {key!r}: missing choice")
            return ChoiceAnswer(
                choice=choice,
                probabilities=JevClient._float_map(value.get("probabilities")),
                confidence=JevClient._optional_float(value.get("confidence")),
            )
        if answer_type == "score":
            score = value.get("score")
            if not isinstance(score, (int, float)) or isinstance(score, bool):
                raise Exception(f"Invalid score answer for {key!r}: missing score")
            legend = value.get("legend")
            return ScoreAnswer(
                score=float(score),
                probabilities=JevClient._float_map(value.get("probabilities")),
                legend=dict(legend) if isinstance(legend, dict) else {},
                confidence=JevClient._optional_float(value.get("confidence")),
            )
        raise Exception(f"Invalid answer for {key!r}: unexpected type {answer_type!r}")

    @staticmethod
    def _parse_usage(usage: Any) -> DecisionsUsage:
        if not isinstance(usage, dict):
            return DecisionsUsage()
        return DecisionsUsage(
            input_tokens=JevClient._int_or_zero(usage.get("input_tokens")),
            output_tokens=JevClient._int_or_zero(usage.get("output_tokens")),
            cost=JevClient._optional_float(usage.get("cost")) or 0.0,
        )

    @staticmethod
    def _noul_criteria(
        criteria: Mapping[str, CriteriaValue] | None,
        criteria_true: CriteriaValue,
        criteria_false: CriteriaValue,
    ) -> dict[str, CriteriaValue] | None:
        if criteria is not None:
            return dict(criteria)
        if criteria_true is None and criteria_false is None:
            return None
        out: dict[str, CriteriaValue] = {}
        if criteria_true is not None:
            out["true"] = criteria_true
        if criteria_false is not None:
            out["false"] = criteria_false
        return out

    @staticmethod
    def _float_map(value: Any) -> dict[str, float]:
        if not isinstance(value, dict):
            return {}
        out: dict[str, float] = {}
        for key, item in value.items():
            if isinstance(item, (int, float)) and not isinstance(item, bool):
                out[str(key)] = float(item)
        return out

    @staticmethod
    def _optional_float(value: Any) -> float | None:
        if isinstance(value, (int, float)) and not isinstance(value, bool):
            return float(value)
        if isinstance(value, str) and value.strip() != "":
            try:
                return float(value)
            except ValueError:
                return None
        return None

    @staticmethod
    def _int_or_zero(value: Any) -> int:
        if isinstance(value, bool):
            return 0
        if isinstance(value, int):
            return value
        if isinstance(value, float):
            return int(value)
        return 0


# Re-export for callers that catch AI errors from decide().
__all__ = [
    "AIError",
    "Answer",
    "ChoiceAnswer",
    "DecisionsResponse",
    "DecisionsUsage",
    "JevClient",
    "NoulAnswer",
    "ScoreAnswer",
    "State",
]
