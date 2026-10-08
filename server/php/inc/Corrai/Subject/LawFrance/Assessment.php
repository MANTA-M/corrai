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
        $studentNames = [];
        foreach ($students as $student) {
            if ($student->id !== null && $student->id !== '') {
                $studentNames[$student->id] = $student->name;
            }
        }

        foreach ($unclassified as $submission) {
            $studentId = self::studentIdBeforeName($nameToStudentId, $submission->name);
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
