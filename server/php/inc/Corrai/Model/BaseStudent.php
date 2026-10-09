<?php

namespace Corrai\Model;

use Exception;
use Corrai\Model\SubmissionFile;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\MenuLabels;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Store\StoreConflictException;
use Corrai\Utils\Http\WSException;

/**
 * Shared student model. Subject packages may provide a concrete Student.
 */
abstract class BaseStudent implements HasStatusInterface, HasI18nInterface, HasMenuInterface
{
    public ?string $id = null;
    public string $school_id = '';
    public string $user_id = '';
    public string $assessment_id = '';

    /**
     * Display name.
     */
    public string $name = '';

    public string $status = '';

    /**
     * Student state labels. Subject students may add states.
     *
     * @var array<string, array<string, string>>
     */
    private const STATUS_LABELS = [
        'pending' => [
            'en' => 'Pending',
            'fr' => 'En attente',
            'ru' => 'Ожидает',
            'uk' => 'Очікує',
            'es' => 'Pendiente',
            'pt' => 'Pendente',
            'ro' => 'În așteptare',
            'de' => 'Ausstehend',
        ],
        'under_correction' => [
            'en' => 'Under correction',
            'fr' => 'En correction',
            'ru' => 'В коррекции',
            'uk' => 'В корекції',
            'es' => 'En corrección',
            'pt' => 'Em correção',
            'ro' => 'În corecție',
            'de' => 'In Korrektur',
        ],
        'graded' => [
            'en' => 'Graded',
            'fr' => 'Noté',
            'ru' => 'Оценён',
            'uk' => 'Оцінено',
            'es' => 'Calificado',
            'pt' => 'Classificado',
            'ro' => 'Notat',
            'de' => 'Benotet',
        ],
        'error' => [
            'en' => 'Error',
            'fr' => 'Erreur',
            'ru' => 'Ошибка',
            'uk' => 'Помилка',
            'es' => 'Error',
            'pt' => 'Erro',
            'ro' => 'Eroare',
            'de' => 'Fehler',
        ],
    ];

    public ?float $mark = null;

    /**
     * Teacher appreciation, stored as Markdown.
     */
    public string $appreciation = '';

    /** @var string|null Last known ETag for conditional updates */
    public ?string $etag = null;

    public static function from_array(array $data): static
    {
        $student = new static();
        $student->id = $data['id'] ?? null;
        $student->school_id = $data['school_id'] ?? '';
        $student->user_id = $data['user_id'] ?? '';
        $student->assessment_id = $data['assessment_id'] ?? '';
        $student->name = (string) ($data['name'] ?? $data['student'] ?? '');
        $student->status = (string) ($data['status'] ?? '');
        $mark = $data['mark'] ?? null;
        $student->mark = is_numeric($mark) ? (float) $mark : null;
        $student->appreciation = (string) ($data['appreciation'] ?? '');
        return $student;
    }

    public function validate(): void
    {
        if (trim($this->school_id) === '' || trim($this->user_id) === '' || trim($this->assessment_id) === '') {
            throw new WSException('Student school_id, user_id and assessment_id are required', 400);
        }
        if (trim($this->name) === '') {
            throw new WSException('Student name is required', 400);
        }
    }

    public static function from_hash(string $hash): static
    {
        $store = ObjectStore::getInstance();
        $prefix = $store->resolveIdPointer($hash);
        $parsed = ObjectStore::parseNodePrefix($prefix);
        if ($parsed['kind'] !== 'student') {
            throw new Exception("Hash $hash does not point to a student");
        }

        $attrKey = ObjectStore::assessmentStudentAttrKey(
            $parsed['school_id'],
            $parsed['teacher_id'],
            $parsed['assessment_id'],
            $parsed['student_id']
        );
        if (!$store->exists($attrKey)) {
            throw new Exception("Student with hash $hash does not exist");
        }

        $loaded = $store->getJson($attrKey);
        $targetClass = static::class;
        if ($targetClass === self::class) {
            $assessmentId = $parsed['assessment_id'] ?? '';
            if ($assessmentId !== '') {
                try {
                    $assessment = BaseAssessment::from_hash($assessmentId);
                    $targetClass = $assessment->studentClass();
                } catch (\Throwable $e) {
                    $targetClass = Student::class;
                }
            } else {
                $targetClass = Student::class;
            }
        }
        $student = $targetClass::from_array($loaded['data']);
        $student->id = $parsed['student_id'];
        $student->school_id = $parsed['school_id'];
        $student->user_id = $parsed['teacher_id'];
        $student->assessment_id = $parsed['assessment_id'];
        $student->etag = $loaded['etag'];
        return $student;
    }

    public function attrKey(): string
    {
        return ObjectStore::assessmentStudentAttrKey(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            $this->id
        );
    }

    public function prefix(): string
    {
        return ObjectStore::assessmentStudentPrefix(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            $this->id
        );
    }

    public function compiledSubmissionKey(): string
    {
        return ObjectStore::assessmentStudentCompiledSubmissionKey(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            (string) $this->id
        );
    }

    /**
     * Persist with optional If-Match. Retries a few times on conflict when $retry is true.
     *
     * A created or changed mark notifies the assessment, unless $notifyMarkChange is false.
     */
    public function save(bool $retry = true, bool $notifyMarkChange = true): void
    {
        if ($this->id === null || $this->id === '') {
            $this->id = HashId::create();
            $this->etag = null;
        }
        $this->validate();

        $payload = [
            'name' => $this->name,
            'status' => $this->status,
            'mark' => $this->mark,
            'appreciation' => $this->appreciation,
        ];

        $store = ObjectStore::getInstance();
        $existed = $store->exists($this->attrKey());
        $previousMark = $existed ? self::markFromPayload($store->getJson($this->attrKey())['data'] ?? []) : null;

        $attempts = $retry ? 5 : 1;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $this->etag = $store->putJson($this->attrKey(), $payload, $this->etag);
                $store->setIdPointer($this->id, $this->prefix());
                if ($notifyMarkChange && ($this->mark !== null || self::markCreatedOrChanged($existed, $previousMark, $this->mark))) {
                    $this->notifyAssessmentOfMarkChange();
                }
                return;
            } catch (StoreConflictException $e) {
                if ($i === $attempts - 1) {
                    throw $e;
                }
                $loaded = $store->getJson($this->attrKey());
                $this->etag = $loaded['etag'];
                $previousMark = self::markFromPayload($loaded['data'] ?? []);
                $existed = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function markFromPayload(array $data): ?float
    {
        $mark = $data['mark'] ?? null;
        return is_numeric($mark) ? (float) $mark : null;
    }

    private static function markCreatedOrChanged(bool $existed, ?float $previousMark, ?float $mark): bool
    {
        if (!$existed) {
            return $mark !== null;
        }
        if ($previousMark === null || $mark === null) {
            return $previousMark !== $mark;
        }
        return abs($previousMark - $mark) >= 0.0000001;
    }

    private function notifyAssessmentOfMarkChange(): void
    {
        if ($this->assessment_id === '') {
            return;
        }
        $assessment = BaseAssessment::from_hash($this->assessment_id);
        $assessment->on_mark_change($this);
    }

    public function getAssessment(): BaseAssessment
    {
        if ($this->assessment_id === '') {
            throw new WSException('Student is not associated with an assessment', 400);
        }
        return BaseAssessment::from_hash($this->assessment_id);
    }

    /**
     * @return list<SubmissionFile>
     */
    public function getSubmissions(): array
    {
        if ($this->assessment_id === '' || $this->id === null || $this->id === '') {
            return [];
        }
        $assessment = $this->getAssessment();
        $files = [];
        foreach ($assessment->listFileModels() as $file) {
            if ($file instanceof SubmissionFile && $file->student === $this->id) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Statuses that mean the copy has already been transcribed.
     *
     * @var list<string>
     */
    private const TRANSCRIBED_STATUSES = ['transcribed', 'correction_ready', 'corrected'];

    /**
     * Ask transcription for this student's copies that are not yet transcribed.
     *
     * @return list<SubmissionFile>
     */
    public function transcribe(): array
    {
        $pending = [];
        foreach ($this->getSubmissions() as $submission) {
            if (in_array($submission->status, self::TRANSCRIBED_STATUSES, true)) {
                continue;
            }
            $submission->on_correction_asked();
            $pending[] = $submission;
        }
        return $pending;
    }

    /**
     * Correct this student. Can be overridden by subject student implementations.
     *
     * @return mixed
     */
    public function correct(): mixed
    {
        $assessment = $this->getAssessment();
        $submissions = $this->getSubmissions();
        foreach ($submissions as $submission) {
            $assessment->correctSubmission($submission->id);
        }
        return $submissions;
    }

    public function delete(): void
    {
        if ($this->id === null || $this->id === '') {
            throw new Exception('Cannot delete student without id');
        }
        $store = ObjectStore::getInstance();
        $store->deletePrefix($this->prefix());
        $store->deleteIdPointer($this->id);
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected static function statusLabelTable(): array
    {
        return self::STATUS_LABELS;
    }

    /**
     * Every student state, as key => label in the queried locale.
     *
     * @return array<string, string>
     */
    public static function statusLabels(?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        $map = [];
        foreach (static::statusLabelTable() as $status => $labels) {
            $map[$status] = MenuLabels::pick($labels, $locale, $status);
        }
        return $map;
    }

    public function get_status(): string
    {
        return $this->status;
    }

    public function get_status_label(?string $locale = null): string
    {
        return static::statusLabels($locale)[$this->status] ?? $this->status;
    }

    /**
     * Multilingual status labels for this student, or translations for a specific status key.
     *
     * @return array<string, mixed>
     */
    public function get_i18n(?string $key = null): array
    {
        $table = static::statusLabelTable();
        if ($key !== null) {
            return $table[$key] ?? [];
        }
        return $table;
    }

    public function to_output(?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'status_label' => $this->get_status_label($locale),
            'mark' => $this->mark,
            'appreciation' => $this->appreciation,
            'menu' => $this->get_menu($locale),
        ];
    }

    /**
     * Icon actions shown on a student row.
     *
     * @return array<int, array{key: string, label: string, icon: string, color: string}>
     */
    public function get_menu(?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        return [
            MenuLabels::item('view', $locale, 'eye', MenuLabels::BLUE, 'student_open'),
            MenuLabels::item('transcribe', $locale, 'text', MenuLabels::BLUE),
            MenuLabels::item('correct', $locale, 'check', MenuLabels::BLUE),
            MenuLabels::item('rename', $locale, 'pencil', MenuLabels::BLUE, 'student_rename'),
            MenuLabels::item('edit_attributes', $locale, 'braces', MenuLabels::MUTED),
            MenuLabels::item('delete', $locale, 'trash', MenuLabels::DANGER, 'student_delete'),
        ];
    }
}
