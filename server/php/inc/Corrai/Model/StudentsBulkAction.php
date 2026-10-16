<?php

namespace Corrai\Model;

use Corrai\Queue\RedisQueue;
use Corrai\Utils\Http\WSException;
use InvalidArgumentException;

/**
 * Bulk transcribe / correct selected students, with optional LLM model routing.
 *
 * All correction models are enqueued on the Python Redis queue
 * (``corrai:ocr``): Math → ``math_correct_students``, Law → ``correct_students``
 * (or ``jev_correct_students`` for Jev).
 */
class StudentsBulkAction
{
    public const ACTION_TRANSCRIBE = 'transcribe';
    public const ACTION_CORRECT = 'correct';

    public const MODEL_CLAUDE = 'claude';
    public const MODEL_MISTRAL = 'mistral';
    public const MODEL_GEMINI = 'gemini';
    public const MODEL_GPT = 'gpt';
    public const MODEL_JEV = 'jev';

    public const GPT_MODEL_ID = 'openai/gpt-6.1-sol';
    public const CLAUDE_MODEL_ID = 'anthropic/claude-sonnet-5.5';
    public const MISTRAL_MODEL_ID = 'mistralai/mistral-large';
    public const GEMINI_MODEL_ID = 'google/gemini-3.1-pro-preview';

    /** @var list<string> */
    public const MODELS = [
        self::MODEL_CLAUDE,
        self::MODEL_MISTRAL,
        self::MODEL_GEMINI,
        self::MODEL_GPT,
        self::MODEL_JEV,
    ];

    /**
     * @param list<string> $studentIds
     */
    public static function run(
        BaseAssessment $assessment,
        array $studentIds,
        string $action,
        ?string $model = null
    ): void {
        $studentIds = array_values(array_filter(
            array_map(static fn ($id) => is_string($id) ? trim($id) : '', $studentIds),
            static fn (string $id) => $id !== ''
        ));
        if ($studentIds === []) {
            throw new WSException('No students selected', 400);
        }

        if ($action === self::ACTION_TRANSCRIBE) {
            self::transcribe($assessment, $studentIds);
            return;
        }

        if ($action === self::ACTION_CORRECT) {
            if ($model === null || $model === '' || !in_array($model, self::MODELS, true)) {
                throw new WSException('Invalid or missing model for correction', 400);
            }
            self::correct($assessment, $studentIds, $model);
            return;
        }

        throw new WSException('Unknown bulk action: ' . $action, 400);
    }

    /**
     * @param list<string> $studentIds
     */
    private static function transcribe(BaseAssessment $assessment, array $studentIds): void
    {
        foreach ($studentIds as $studentId) {
            $assessment->getStudent($studentId)->transcribe();
        }
    }

    /**
     * @param list<string> $studentIds
     */
    private static function correct(
        BaseAssessment $assessment,
        array $studentIds,
        string $model
    ): void {
        $isMath = self::isMathAssessment($assessment);

        if ($model === self::MODEL_JEV) {
            if ($isMath) {
                throw new WSException('Jev correction is not available for Math assessments', 400);
            }
            self::markUnderCorrection($assessment, $studentIds);
            RedisQueue::getInstance()->enqueuePythonAssessmentTask(
                (string) $assessment->id,
                'jev_correct_students',
                ['student_ids' => $studentIds]
            );
            return;
        }

        $modelId = self::openRouterModelId($model);
        $task = $isMath ? 'math_correct_students' : 'correct_students';
        self::markUnderCorrection($assessment, $studentIds);
        RedisQueue::getInstance()->enqueuePythonAssessmentTask(
            (string) $assessment->id,
            $task,
            [
                'student_ids' => $studentIds,
                'model' => $modelId,
            ]
        );
    }

    private static function isMathAssessment(BaseAssessment $assessment): bool
    {
        return strcasecmp(trim((string) $assessment->subject), 'Math') === 0;
    }

    public static function openRouterModelId(string $model): string
    {
        return match ($model) {
            self::MODEL_CLAUDE => self::CLAUDE_MODEL_ID,
            self::MODEL_MISTRAL => self::MISTRAL_MODEL_ID,
            self::MODEL_GEMINI => self::GEMINI_MODEL_ID,
            self::MODEL_GPT => self::GPT_MODEL_ID,
            default => throw new InvalidArgumentException('Unsupported correction model: ' . $model),
        };
    }

    /**
     * @param list<string> $studentIds
     */
    private static function markUnderCorrection(
        BaseAssessment $assessment,
        array $studentIds
    ): void {
        foreach ($studentIds as $studentId) {
            $student = $assessment->getStudent($studentId);
            $submissions = $student->getSubmissions();
            if ($submissions === []) {
                throw new WSException('No submissions found for student ' . $student->name, 400);
            }
            $student->status = 'under_correction';
            $student->save();
        }
    }
}
