<?php

namespace Corrai\Subject\LawFrance;

use Corrai\Model\BaseAssessment;
use Corrai\Model\SubjectFile;
use Corrai\Model\SubmissionFile;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\MenuLabels;

class Assessment extends BaseAssessment
{
    public string $subject = 'Law';
    public ?string $country = 'fr';

    public const NAMES = [
        'en' => 'Law France',
        'fr' => 'Droit France',
        'ru' => 'Право Франция',
        'uk' => 'Право Франція',
        'es' => 'Derecho Francia',
        'pt' => 'Direito Portugal',
        'ro' => 'Drept România',
        'de' => 'Recht Deutschland',
    ];

    public function subjectFileClass(): string
    {
        return SubjectFile::class;
    }

    public function submissionClass(): string
    {
        return Submission::class;
    }

    public function studentClass(): string
    {
        return Student::class;
    }

    public function startCorrection(): array
    {
        if ($this->unclassifiedFileIds() !== []) {
            return $this->correctUnclassifiedFiles();
        }

        $ids = $this->pendingSubmissionIds();
        if ($ids === []) {
            throw new WSException('No copies to correct', 400);
        }
        foreach ($ids as $fileId) {
            $this->correctSubmission($fileId);
        }
        return $this->list_files();
    }

    /**
     * True while an unassigned copy has not finished identification.
     * Crop marks copies transcribed before the student number is read, so status
     * alone must not start affectation.
     */
    public function hasUnassignedSubmissionsAwaitingIdentification(?string $excludeFileId = null): bool
    {
        foreach ($this->listFileModels() as $model) {
            if (!$model instanceof SubmissionFile) {
                continue;
            }
            if ($excludeFileId !== null && $model->id === $excludeFileId) {
                continue;
            }
            $studentId = is_string($model->student) ? trim($model->student) : '';
            if ($studentId !== '') {
                continue;
            }
            if (!$this->hasIdentifyEvent($model)) {
                return true;
            }
        }
        return false;
    }

    private function hasIdentifyEvent(SubmissionFile $file): bool
    {
        try {
            foreach ($file->listEvents() as $event) {
                if (($event['name'] ?? '') === SubmissionTask3Identify::IDENTIFY_EVENT) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * Attach each unclassified transcribed copy to the student of the assigned
     * copy whose name comes immediately before it.
     */
    public function allocateSubmission(): void
    {
        $unclassified = [];
        /** @var array<string, string> $nameToStudentId */
        $nameToStudentId = [];
        foreach ($this->listFileModels() as $model) {
            if (!$model instanceof SubmissionFile) {
                continue;
            }
            $studentId = is_string($model->student) ? trim($model->student) : '';
            if ($studentId === '') {
                $unclassified[] = $model;
                continue;
            }
            if ($model->name !== '' && !isset($nameToStudentId[$model->name])) {
                $nameToStudentId[$model->name] = $studentId;
            }
        }

        foreach ($unclassified as $submission) {
            if ($submission->status !== 'transcribed') {
                // #region agent log
                @file_put_contents('/home/maintainer/corrai_test/.cursor/debug-3c9dba.log', json_encode(['sessionId' => '3c9dba', 'hypothesisId' => 'D', 'location' => 'Assessment.php:allocateSubmission', 'message' => 'abort: unclassified not transcribed', 'data' => ['file' => $submission->name, 'status' => $submission->status, 'unclassified' => array_map(static fn($s) => ['name' => $s->name, 'status' => $s->status], $unclassified)], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
                // #endregion
                return;
            }
        }

        $students = $this->listStudentModels();
        if ($students === []) {
            $message = 'Cannot allocate submissions: the assessment has no students';
            if ($this->id !== null && $this->id !== '' && $this->school_id !== '' && $this->user_id !== '') {
                $this->appendEvent($message);
            }
            throw new WSException($message, 400);
        }

        ksort($nameToStudentId, SORT_STRING);
        // #region agent log
        @file_put_contents('/home/maintainer/corrai_test/.cursor/debug-3c9dba.log', json_encode(['sessionId' => '3c9dba', 'hypothesisId' => 'C', 'location' => 'Assessment.php:allocateSubmission', 'message' => 'name map before assign', 'data' => ['nameToStudentId' => $nameToStudentId, 'unclassified' => array_map(static fn($s) => $s->name, $unclassified)], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
        // #endregion
        $studentNames = [];
        foreach ($students as $student) {
            if ($student->id !== null && $student->id !== '') {
                $studentNames[$student->id] = $student->name;
            }
        }

        foreach ($unclassified as $submission) {
            $studentId = self::studentIdBeforeName($nameToStudentId, $submission->name);
            // #region agent log
            $predecessor = null;
            foreach ($nameToStudentId as $assignedName => $mappedStudentId) {
                if (strcmp($assignedName, $submission->name) >= 0) {
                    break;
                }
                $predecessor = ['name' => $assignedName, 'studentId' => $mappedStudentId];
            }
            @file_put_contents('/home/maintainer/corrai_test/.cursor/debug-3c9dba.log', json_encode(['sessionId' => '3c9dba', 'hypothesisId' => 'B', 'location' => 'Assessment.php:allocateSubmission', 'message' => 'predecessor decision', 'data' => ['file' => $submission->name, 'studentId' => $studentId, 'predecessor' => $predecessor], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
            // #endregion
            if ($studentId === null) {
                continue;
            }
            $submission->student = $studentId;
            $submission->saveAttributes();
            $label = $studentNames[$studentId] ?? $studentId;
            $submission->appendEvent('Allocated to student ' . $label);
        }
    }

    /**
     * Student of the assigned copy whose name is the greatest one strictly before $name.
     *
     * @param array<string, string> $nameToStudentId names sorted ascending
     */
    private static function studentIdBeforeName(array $nameToStudentId, string $name): ?string
    {
        $previous = null;
        foreach ($nameToStudentId as $assignedName => $studentId) {
            if (strcmp($assignedName, $name) >= 0) {
                break;
            }
            $previous = $studentId;
        }
        return $previous;
    }

    /**
     * Queue generation of the French correction grid.
     */
    public function create_correction_grid(): void
    {
        if ($this->id === null || $this->id === '') {
            throw new WSException('Assessment is incomplete', 400);
        }
        RedisQueue::getInstance()->enqueueAssessment($this->id, AssTaskGridCreation::class);
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected static function statusLabelTable(): array
    {
        return array_merge(parent::statusLabelTable(), [
            'create_correction_grid' => [
                'en' => 'Creating the correction grid',
                'fr' => 'Création de la grille',
                'ru' => 'Создание сетки оценивания',
                'uk' => 'Створення сітки оцінювання',
                'es' => 'Creación de la rúbrica',
                'pt' => 'Criação da grelha',
                'ro' => 'Crearea grilei',
                'de' => 'Korrekturraster wird erstellt',
            ],
            'correction_grid_generated' => [
                'en' => 'Correction grid ready',
                'fr' => 'Grille de correction prête',
                'ru' => 'Сетка оценивания готова',
                'uk' => 'Сітка оцінювання готова',
                'es' => 'Rúbrica lista',
                'pt' => 'Grelha de correção pronta',
                'ro' => 'Grilă de corectare gata',
                'de' => 'Korrekturraster bereit',
            ],
        ]);
    }

    public function get_menu(?string $locale = null): array
    {
        $items = parent::get_menu($locale);
        $items[] = MenuLabels::item('create_correction_grid', MenuLabels::locale($locale), '', MenuLabels::BLUE);
        return $items;
    }
}
