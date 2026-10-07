<?php

namespace Corrai\Model;

use Exception;
use Corrai\Clients\Openrouter\LlmClientFactory;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\MenuLabels;
use Corrai\Utils\Image\HeicToWebp;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Http\WSException;
use Corrai\Subject\Catalog;
use Corrai\Subject\SubjectPages;
use Corrai\Subject\Dictation\Task1Correcting as Dictation;
use Corrai\Subject\AssessmentFactory;
use Corrai\Subject\Law\Task1Transcribing as Law;
use Corrai\Subject\Math\Task1Transcribing as MathPipeline;
use Corrai\Subject\Other\Task1Transcribing as Other;
use Corrai\Subject\Physics\Task1Transcribing as Physics;

/**
 * Shared assessment model. Subject packages provide a concrete Assessment.
 */
abstract class BaseAssessment
{
    /**
     * The unique identifier of the assessment (7-char hash).
     */
    public ?string $id = null;

    /**
     * Parent school hash.
     */
    public string $school_id = '';

    /**
     * Owner user (teacher) hash.
     */
    public string $user_id = '';

    /**
     * The name of the assessment.
     */
    public string $name = '';

    /**
     * Subject name for this assessment (Math, Physics, Dictation, Law, Other).
     */
    public string $subject = '';

    /**
     * Optional country for this assessment's subject. Null when not specified.
     */
    public ?string $country = null;

    /**
     * Optional level for this assessment's subject. Null when not specified.
     */
    public ?string $level = null;

    /**
     * Locale code of the correction feedback written by the LLM. Set at creation.
     */
    public string $correction_language = 'fr';

    /**
     * English language names used as LLM directives.
     *
     * @var array<string, string>
     */
    public const LOCALE_NAMES = [
        'en' => 'English',
        'fr' => 'French',
        'ru' => 'Russian',
        'uk' => 'Ukrainian',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'ro' => 'Romanian',
        'de' => 'German',
    ];

    /**
     * The date of the assessment (YYYY-MM-DD).
     */
    public string $date = '';

    /**
     * ISO-8601 creation timestamp.
     */
    public string $created_at = '';

    /**
     * Latest Stripe Checkout Session created for this correction batch.
     */
    public string $stripe_checkout_session_id = '';

    /**
     * Checkout Session that was paid and whose correction was already enqueued.
     */
    public string $stripe_paid_session_id = '';

    /**
     * Cents charged per copy on the current Checkout Session, after the teacher discount.
     */
    public int $stripe_unit_amount = 100;

    /**
     * Number of students created on this assessment. Null until the first student is created.
     */
    public ?int $assessed_students_number = null;

    /**
     * Mean of the students' marks. Null when no student has a mark.
     */
    public ?float $mark_average = null;

    /**
     * Lowest student mark. Null when no student has a mark.
     */
    public ?float $mark_min = null;

    /**
     * Highest student mark. Null when no student has a mark.
     */
    public ?float $mark_max = null;

    /**
     * Allowed file type tags. Empty / unknown is stored as an empty string.
     */
    public const FILE_TYPES = ['subject', 'solution', 'submission', 'instructions', 'correction', 'debug'];

    /**
     * Concrete File class for this assessment's subject.
     *
     * Generic Assessment instances resolve via AssessmentFactory so uploads still get the
     * subject File even when the API loaded Corrai\Model\Assessment.
     *
     * @return class-string<SubmissionFile>
     */
    public function fileClass(): string
    {
        $assessmentClass = AssessmentFactory::assessmentClass(
            $this->subject,
            $this->country ?? '',
            $this->level ?? ''
        );
        if ($assessmentClass !== static::class) {
            $method = new \ReflectionMethod($assessmentClass, 'fileClass');
            if ($method->getDeclaringClass()->getName() !== self::class) {
                /** @var BaseAssessment $subjectAssessment */
                $subjectAssessment = new $assessmentClass();

                return $subjectAssessment->fileClass();
            }
        }

        return SubmissionFile::class;
    }

    /**
     * File class used for submissions that have no stored class.
     *
     * Generic Assessment instances resolve via AssessmentFactory, same as fileClass().
     *
     * @return class-string<SubmissionFile>
     */
    public function submissionClass(): string
    {
        $assessmentClass = AssessmentFactory::assessmentClass(
            $this->subject,
            $this->country ?? '',
            $this->level ?? ''
        );
        if ($assessmentClass !== static::class) {
            $method = new \ReflectionMethod($assessmentClass, 'submissionClass');
            if ($method->getDeclaringClass()->getName() !== self::class) {
                /** @var BaseAssessment $subjectAssessment */
                $subjectAssessment = new $assessmentClass();

                return $subjectAssessment->submissionClass();
            }
        }

        return SubmissionFile::class;
    }

    /**
     * Concrete assessment class stored in S3 for this subject, country, and level.
     *
     * The runtime class is kept when it is that catalog class or a subclass of it.
     * A generic Assessment with Dictation / fr / CM2 therefore stores
     * Corrai\Subject\DictationFranceCM2\Assessment.
     *
     * @return class-string<BaseAssessment>
     */
    public function storedClass(): string
    {
        $resolved = AssessmentFactory::assessmentClass(
            $this->subject,
            $this->country ?? '',
            $this->level ?? ''
        );
        if (is_a(static::class, $resolved, true)) {
            return static::class;
        }
        return $resolved;
    }

    /**
     * Attribute document written to S3, including the concrete class.
     *
     * @return array<string, mixed>
     */
    public function attributePayload(): array
    {
        return [
            'name' => $this->name,
            'subject' => $this->subject,
            'country' => self::optionalAttribute($this->country),
            'level' => self::optionalAttribute($this->level),
            'correction_language' => $this->correction_language,
            'date' => $this->date,
            'created_at' => $this->created_at,
            'stripe_checkout_session_id' => $this->stripe_checkout_session_id,
            'stripe_paid_session_id' => $this->stripe_paid_session_id,
            'stripe_unit_amount' => $this->stripe_unit_amount,
            'assessed_students_number' => $this->assessed_students_number,
            'mark_average' => $this->mark_average,
            'mark_min' => $this->mark_min,
            'mark_max' => $this->mark_max,
            'class' => $this->storedClass(),
        ];
    }

    public static function from_array(array $data): static
    {
        $assessment = new static();
        $assessment->id = $data['id'] ?? null;
        $assessment->school_id = $data['school_id'] ?? '';
        $assessment->user_id = $data['user_id'] ?? ($data['author'] ?? '');
        $assessment->name = $data['name'] ?? '';
        $assessment->subject = $data['subject'] ?? '';
        $assessment->country = self::optionalAttribute($data['country'] ?? null);
        $assessment->level = self::optionalAttribute($data['level'] ?? null);
        $assessment->correction_language = self::normalizeLocale($data['correction_language'] ?? null);
        $assessment->date = $data['date'] ?? '';
        $assessment->created_at = $data['created_at'] ?? '';
        $assessment->stripe_checkout_session_id = self::stringAttribute($data['stripe_checkout_session_id'] ?? null);
        $assessment->stripe_paid_session_id = self::stringAttribute($data['stripe_paid_session_id'] ?? null);
        $assessment->stripe_unit_amount = self::unitAmountAttribute($data['stripe_unit_amount'] ?? null);
        $assessment->assessed_students_number = self::nonNegativeIntAttribute($data['assessed_students_number'] ?? null);
        $assessment->mark_average = self::nullableNumberAttribute($data['mark_average'] ?? null);
        $assessment->mark_min = self::nullableNumberAttribute($data['mark_min'] ?? null);
        $assessment->mark_max = self::nullableNumberAttribute($data['mark_max'] ?? null);
        return $assessment;
    }

    /**
     * Country and level are null when the client sends null, omits them, or sends a blank string.
     */
    public static function stringAttribute(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Cents per copy stored with a Checkout Session. Missing values stay at the full price.
     */
    public static function unitAmountAttribute(mixed $value): int
    {
        if (is_int($value) && $value >= 0 && $value <= 100) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            $amount = (int) $value;
            if ($amount >= 0 && $amount <= 100) {
                return $amount;
            }
        }
        return 100;
    }

    public static function nonNegativeIntAttribute(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if ((is_float($value) || (is_string($value) && is_numeric($value))) && is_numeric($value)) {
            $int = (int) $value;
            return $int >= 0 ? $int : null;
        }
        return null;
    }

    public static function nullableNumberAttribute(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) $value;
        }
        return null;
    }

    public static function optionalAttribute(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A supported locale code. Missing or unknown values fall back to French.
     */
    public static function normalizeLocale(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return 'fr';
        }
        $locale = strtolower(trim($value));
        $dash = strpos($locale, '-');
        if ($dash !== false) {
            $locale = substr($locale, 0, $dash);
        }
        if (!isset(self::LOCALE_NAMES[$locale])) {
            return 'fr';
        }
        return $locale;
    }

    /**
     * English language name for LLM prompts.
     */
    public function correctionLanguageName(): string
    {
        return self::LOCALE_NAMES[$this->correction_language] ?? self::LOCALE_NAMES['fr'];
    }

    /**
     * Validate required fields. Throws WSException(400) on failure.
     */
    public function validate(): void
    {
        if (trim($this->school_id) === '') {
            throw new WSException('Assessment school_id is required', 400);
        }
        if (trim($this->user_id) === '') {
            throw new WSException('Assessment user_id is required', 400);
        }
        if (trim($this->subject) === '') {
            throw new WSException('Assessment subject is required', 400);
        }
        if (trim($this->date) !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
                throw new WSException('Assessment date must be YYYY-MM-DD', 400);
            }
            $parts = explode('-', $this->date);
            if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                throw new WSException('Assessment date is not a valid calendar date', 400);
            }
        }
    }

    /**
     * Load an assessment from its hash via the _id pointer.
     *
     * The concrete class is the class attribute stored in S3.
     */
    public static function from_hash(string $hash): self
    {
        $store = ObjectStore::getInstance();
        $prefix = $store->resolveIdPointer($hash);
        $parsed = ObjectStore::parseNodePrefix($prefix);
        if ($parsed['kind'] !== 'assessment') {
            throw new Exception("Invalid assessment path for hash $hash");
        }
        $schoolId = $parsed['school_id'];
        $userId = $parsed['teacher_id'];
        $assessmentId = $parsed['assessment_id'];
        $attrKey = ObjectStore::assessmentAttrKey($schoolId, $userId, $assessmentId);

        if (!$store->exists($attrKey)) {
            throw new Exception("Assessment with hash $hash does not exist");
        }

        $loaded = $store->getJson($attrKey);
        return AssessmentFactory::fromAttributes($loaded['data'], $schoolId, $userId, $assessmentId);
    }

    /**
     * List all assessments owned by the given user (teacher).
     *
     * @return array Array of assessment output arrays
     */
    public static function list_for_author(string $userId): array
    {
        $user = User::from_hash($userId);
        $assessments = [];
        foreach ($user->assessments() as $assessment) {
            $assessments[] = $assessment->to_output();
        }
        return $assessments;
    }

    /**
     * Localized subject name from the concrete assessment's NAMES table.
     */
    public function localizedLabel(string $locale): string
    {
        $names = [];
        if (defined(static::class . '::NAMES')) {
            $value = constant(static::class . '::NAMES');
            if (is_array($value)) {
                $names = $value;
            }
        }
        return MenuLabels::pick($names, $locale, $this->subject);
    }

    /**
     * Actions shown on the assessment page.
     *
     * Text buttons leave icon empty. Start correction appears only when a submission exists.
     *
     * @return array<int, array{key: string, label: string, icon: string, color: string}>
     */
    public function get_menu(string $locale): array
    {
        $items = [
            MenuLabels::item('edit', $locale, '', MenuLabels::BLUE),
            MenuLabels::item('delete', $locale, '', MenuLabels::DANGER),
            MenuLabels::item('edit_subject', $locale, '', MenuLabels::MUTED),
            MenuLabels::item('add_copies', $locale, '', MenuLabels::BLUE),
        ];
        if ($this->hasSubmission()) {
            $items[] = MenuLabels::item('start_correction', $locale, '', MenuLabels::BLUE);
            $items[] = MenuLabels::item('test_correction', $locale, '', MenuLabels::MUTED);
        }
        return $items;
    }

    private function hasSubmission(): bool
    {
        foreach ($this->listFileModels() as $file) {
            if ($file->type === 'submission') {
                return true;
            }
        }
        return false;
    }

    /**
     * Assessment state labels.
     *
     * @var array<string, array<string, string>>
     */
    private const STATUS_LABELS = [
        'draft' => [
            'en' => 'Draft',
            'fr' => 'Brouillon',
            'ru' => 'Черновик',
            'uk' => 'Чернетка',
            'es' => 'Borrador',
            'pt' => 'Rascunho',
            'ro' => 'Ciornă',
            'de' => 'Entwurf',
        ],
        'correcting' => [
            'en' => 'Correcting',
            'fr' => 'Correction en cours',
            'ru' => 'Проверяется',
            'uk' => 'Перевіряється',
            'es' => 'Corrigiendo',
            'pt' => 'A corrigir',
            'ro' => 'În corectare',
            'de' => 'Wird korrigiert',
        ],
        'corrected' => [
            'en' => 'Corrected',
            'fr' => 'Corrigé',
            'ru' => 'Проверено',
            'uk' => 'Перевірено',
            'es' => 'Corregido',
            'pt' => 'Corrigido',
            'ro' => 'Corectat',
            'de' => 'Korrigiert',
        ],
    ];

    /**
     * Every assessment state, as key => label in the queried locale.
     *
     * @return array<string, string>
     */
    public static function statusLabels(?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        $map = [];
        foreach (self::STATUS_LABELS as $status => $labels) {
            $map[$status] = MenuLabels::pick($labels, $locale, $status);
        }
        return $map;
    }

    /**
     * File states for this assessment's subject, as key => label.
     *
     * @return array<string, string>
     */
    public function fileStatusLabels(?string $locale = null): array
    {
        $class = $this->fileClass();
        return $class::statusLabels($locale);
    }

    public function to_output(?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'subject' => $this->subject,
            'country' => self::optionalAttribute($this->country),
            'level' => self::optionalAttribute($this->level),
            'correction_language' => $this->correction_language,
            'date' => $this->date,
            'created_at' => $this->created_at,
            'assessed_students_number' => $this->assessed_students_number,
            'mark_average' => $this->mark_average,
            'mark_min' => $this->mark_min,
            'mark_max' => $this->mark_max,
            'label' => $this->localizedLabel($locale),
            'menu' => $this->get_menu($locale),
        ];
    }

    /**
     * Persist assessment attributes.json and register the _id pointer.
     *
     * Mark statistics are recomputed from the stored students first, so a later
     * save cannot write a stale average, min, or max back over a new mark.
     */
    public function save(): void
    {
        $this->prepareForPersist();
        $this->recomputeStudentStats();
        $this->persist();
    }

    /**
     * Assign an id and creation time, then validate required fields.
     */
    private function prepareForPersist(): void
    {
        if ($this->id === null || $this->id === '') {
            $this->id = HashId::create();
        }
        if ($this->created_at === '') {
            $this->created_at = gmdate('c');
        }

        $this->validate();
    }

    /**
     * Write the current attributes. Callers recompute student stats first.
     */
    private function persist(): void
    {
        $store = ObjectStore::getInstance();
        $attrKey = ObjectStore::assessmentAttrKey($this->school_id, $this->user_id, $this->id);
        $previousClass = null;
        $isNew = !$store->exists($attrKey);
        if (!$isNew) {
            try {
                $previous = $store->getJson($attrKey);
                $previousClass = $previous['data']['class'] ?? null;
            } catch (\Throwable $e) {
                $previousClass = null;
            }
        }

        $payload = $this->attributePayload();
        $store->putJson($attrKey, $payload);
        $store->setIdPointer(
            $this->id,
            ObjectStore::assessmentPrefix($this->school_id, $this->user_id, $this->id)
        );
        if ($previousClass !== $payload['class']) {
            $this->syncFileClasses();
        }

        if ($isNew) {
            $this->createInstructionFileFromTemplate();
        }
    }

    /**
     * Rewrite file attribute documents so their class matches this assessment.
     */
    private function syncFileClasses(): void
    {
        $expected = $this->fileClass();
        if (!InputFile::isFileClass($expected)) {
            return;
        }
        foreach ($this->listFileModels() as $file) {
            if ($file->type === 'subject') {
                continue;
            }
            if ($file->hasStoredClass && $file::class === $expected) {
                continue;
            }
            $file->asClass($expected)->saveAttributes();
        }
    }

    /**
     * Delete the assessment prefix and nested file/student id pointers.
     */
    public function delete(): void
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '' || $this->user_id === '') {
            throw new Exception('Cannot delete assessment without id, school_id and user_id');
        }

        $store = ObjectStore::getInstance();
        foreach ($this->listFileModels() as $file) {
            if ($file->id !== null) {
                $store->deleteIdPointer($file->id);
            }
        }
        foreach ($this->listStudentModels() as $student) {
            if ($student->id !== null) {
                $store->deleteIdPointer($student->id);
            }
        }

        $store->deletePrefix(ObjectStore::assessmentPrefix($this->school_id, $this->user_id, $this->id));
        $store->deleteIdPointer($this->id);
    }

    /**
     * S3 content key for a file belonging to this assessment.
     */
    public function fileContentKey(string $fileId): string
    {
        return $this->getFile($fileId)->contentKey();
    }

    /**
     * @return array<int, InputFile|S3File>
     */
    public function listFileModels(): array
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            return [];
        }

        $files = [];
        $seen = [];
        foreach ($this->storedFileIds() as $fileId) {
            if (isset($seen[$fileId])) {
                continue;
            }
            try {
                $files[] = InputFile::from_hash($fileId);
                $seen[$fileId] = true;
            } catch (\Exception $e) {
                continue;
            }
        }
        foreach ($this->blobFileIds() as $fileId) {
            if (isset($seen[$fileId])) {
                continue;
            }
            try {
                $files[] = S3File::from_hash($fileId);
                $seen[$fileId] = true;
            } catch (\Exception $e) {
                continue;
            }
        }
        $store = ObjectStore::getInstance();
        $students = ObjectStore::assessmentStudentsPrefix($this->school_id, $this->user_id, $this->id);
        foreach ($store->listChildPrefixes($students) as $studentId) {
            $studentPrefix = $students . $studentId . '/';
            foreach ($store->listImmediateFiles($studentPrefix) as $name) {
                if ($name === ObjectStore::ATTR_FILE) {
                    continue;
                }
                try {
                    $directFile = S3File::from_key($studentPrefix . $name);
                    if ($directFile->id !== null && !isset($seen[$directFile->id])) {
                        $files[] = $directFile;
                        $seen[$directFile->id] = true;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
        return $files;
    }

    /**
     * Bare object ids under blobs/.
     *
     * @return string[]
     */
    private function blobFileIds(): array
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            return [];
        }
        $prefix = ObjectStore::assessmentBlobsPrefix($this->school_id, $this->user_id, $this->id);
        return ObjectStore::getInstance()->listImmediateFiles($prefix);
    }

    /**
     * File ids stored under subject/, unclassified/, students/, and the legacy files/ area.
     *
     * @return string[]
     */
    private function storedFileIds(): array
    {
        $store = ObjectStore::getInstance();
        $ids = [];
        $areas = [
            ObjectStore::assessmentSubjectFilesPrefix($this->school_id, $this->user_id, $this->id),
            ObjectStore::assessmentUnclassifiedFilesPrefix($this->school_id, $this->user_id, $this->id),
            ObjectStore::assessmentFilesPrefix($this->school_id, $this->user_id, $this->id),
        ];
        foreach ($areas as $prefix) {
            foreach ($store->listChildPrefixes($prefix) as $fileId) {
                $ids[$fileId] = true;
            }
        }

        $students = ObjectStore::assessmentStudentsPrefix($this->school_id, $this->user_id, $this->id);
        foreach ($store->listChildPrefixes($students) as $studentId) {
            $studentPrefix = $students . $studentId . '/';
            foreach ($store->listChildPrefixes($studentPrefix) as $fileId) {
                $ids[$fileId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return Student[]
     */
    public function listStudentModels(): array
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            return [];
        }

        $store = ObjectStore::getInstance();
        $students = [];
        $prefix = ObjectStore::assessmentStudentsPrefix($this->school_id, $this->user_id, $this->id);
        foreach ($store->listChildPrefixes($prefix) as $studentId) {
            $attrKey = ObjectStore::assessmentStudentAttrKey($this->school_id, $this->user_id, $this->id, $studentId);
            if (!$store->exists($attrKey)) {
                continue;
            }
            try {
                $students[] = Student::from_hash($studentId);
            } catch (\Exception $e) {
                continue;
            }
        }
        return $students;
    }

    /**
     * @return array<string, string> student id => name
     */
    public function studentNameMap(): array
    {
        $map = [];
        foreach ($this->listStudentModels() as $student) {
            if ($student->id !== null) {
                $map[$student->id] = $student->name;
            }
        }
        return $map;
    }

    /**
     * List all files for this assessment.
     *
     * @return array Array of file output arrays
     */
    public function list_files(?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        $names = $this->studentNameMap();
        $out = [];
        foreach ($this->listFileModels() as $file) {
            $studentName = null;
            if ($file->student !== null && isset($names[$file->student])) {
                $studentName = $names[$file->student];
            }
            $out[] = $file->to_output($studentName, $locale);
        }
        return $out;
    }

    /**
     * @return array Array of student output arrays
     */
    public function list_students(?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        $out = [];
        foreach ($this->listStudentModels() as $student) {
            $out[] = $student->to_output($locale);
        }
        return $out;
    }

    /**
     * S3 object names stored directly under the assessment prefix, plus files
     * stored directly at the root of the subject directory (prefixed subject/).
     *
     * @return string[]
     */
    public function listRootObjects(): array
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            return [];
        }
        $store = ObjectStore::getInstance();
        $names = $store->listImmediateFiles(
            ObjectStore::assessmentPrefix($this->school_id, $this->user_id, $this->id)
        );
        foreach ($store->listImmediateFiles(
            ObjectStore::assessmentSubjectFilesPrefix($this->school_id, $this->user_id, $this->id)
        ) as $name) {
            $names[] = 'subject/' . $name;
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /**
     * Immutable events recorded for this assessment, oldest first.
     *
     * @return array<int, array{id: string, timestamp: int, name: string}>
     */
    public function listEvents(): array
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            return [];
        }
        $store = ObjectStore::getInstance();
        $prefix = ObjectStore::assessmentEventsPrefix($this->school_id, $this->user_id, $this->id);
        $events = [];
        foreach ($store->listImmediateFiles($prefix) as $name) {
            if (!str_ends_with($name, '.json')) {
                continue;
            }
            $id = substr($name, 0, -5);
            $timestamp = 0;
            $label = $id;
            try {
                $loaded = $store->getJson($prefix . $name);
                $timestamp = (int) ($loaded['data']['timestamp'] ?? 0);
                $eventName = $loaded['data']['name'] ?? '';
                if (is_string($eventName) && $eventName !== '') {
                    $label = MenuLabels::fileEventLabel($eventName);
                }
            } catch (\Throwable $e) {
                // Keep the object visible in debug history even if its JSON is unreadable.
            }
            $events[] = [
                'id' => $id,
                'timestamp' => $timestamp,
                'name' => $label,
            ];
        }
        usort($events, static function (array $a, array $b): int {
            $byTime = $a['timestamp'] <=> $b['timestamp'];
            if ($byTime !== 0) {
                return $byTime;
            }
            return strcmp($a['name'], $b['name']);
        });
        return $events;
    }

    public function getFile(string $fileId): InputFile|S3File
    {
        $store = ObjectStore::getInstance();
        $pointer = $store->resolveIdPointer($fileId);
        $parsed = ObjectStore::parseNodePrefix($pointer);
        $file = ($parsed['kind'] ?? '') === 'blob'
            ? S3File::from_hash($fileId)
            : InputFile::from_hash($fileId);
        if (
            $file->school_id !== $this->school_id
            || $file->user_id !== $this->user_id
            || $file->assessment_id !== $this->id
        ) {
            throw new WSException("File '$fileId' does not belong to assessment {$this->id}", 404);
        }
        return $file;
    }

    public function getStudent(string $studentId): Student
    {
        $student = Student::from_hash($studentId);
        if (
            $student->school_id !== $this->school_id
            || $student->user_id !== $this->user_id
            || $student->assessment_id !== $this->id
        ) {
            throw new WSException("Student '$studentId' does not belong to assessment {$this->id}", 404);
        }
        return $student;
    }

    /**
     * Create or return an existing student with this display name on the assessment.
     */
    public function findOrCreateStudentByName(string $name): Student
    {
        $name = trim($name);
        if ($name === '') {
            throw new WSException('Student name is required', 400);
        }
        foreach ($this->listStudentModels() as $student) {
            if (strcasecmp($student->name, $name) === 0) {
                return $student;
            }
        }
        return $this->createStudent($name);
    }

    /**
     * Next display name for a copy whose student could not be identified: "Inconnu 1", "Inconnu 2", …
     */
    public function nextUnknownStudentName(): string
    {
        $max = 0;
        foreach ($this->listStudentModels() as $student) {
            if (preg_match('/^Inconnu (\d+)$/iu', trim($student->name), $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }
        return 'Inconnu ' . ($max + 1);
    }

    public function createStudent(string $name, string $status = '', ?float $mark = null): Student
    {
        $student = new Student();
        $student->id = HashId::create();
        $student->school_id = $this->school_id;
        $student->user_id = $this->user_id;
        $student->assessment_id = $this->id;
        $student->name = trim($name);
        $student->status = $status;
        $student->mark = $mark;
        $student->save(false, false);
        $this->on_student_create($student);
        if ($student->mark !== null) {
            $this->on_mark_change($student);
        }
        return $student;
    }

    /**
     * Keep assessed_students_number equal to the students stored on this assessment.
     */
    public function on_student_create(BaseStudent $student): void
    {
        $this->prepareForPersist();
        $this->recomputeStudentStats($student);
        $this->persist();
    }

    /**
     * Recompute mark_average, mark_min and mark_max from the students' marks.
     */
    public function on_mark_change(BaseStudent $student): void
    {
        $this->prepareForPersist();
        $this->recomputeStudentStats($student);
        $this->persist();
    }

    /**
     * Refresh the student count and mark statistics from the students in storage.
     *
     * $focus is the student just created or graded. Their mark is used even when
     * the listing has not caught up with the write yet.
     */
    private function recomputeStudentStats(?BaseStudent $focus = null): void
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '' || $this->user_id === '') {
            return;
        }

        $marks = [];
        $count = 0;
        $seen = false;
        foreach ($this->listStudentModels() as $existing) {
            $count++;
            $isFocus = $focus !== null && $existing->id !== null && $existing->id === $focus->id;
            if ($isFocus) {
                $seen = true;
            }
            $mark = $isFocus ? $focus->mark : $existing->mark;
            if ($mark !== null) {
                $marks[] = $mark;
            }
        }
        if ($focus !== null && !$seen) {
            $count++;
            if ($focus->mark !== null) {
                $marks[] = $focus->mark;
            }
        }

        if ($count > 0 || $this->assessed_students_number !== null) {
            $this->assessed_students_number = $count;
        }
        if ($marks === []) {
            $this->mark_average = null;
            $this->mark_min = null;
            $this->mark_max = null;
        } else {
            $this->mark_min = min($marks);
            $this->mark_max = max($marks);
            $this->mark_average = array_sum($marks) / count($marks);
        }
    }

    /**
     * Remove a student and return their files to the unassigned pool.
     *
     * @return array Updated file list
     */
    public function deleteStudent(string $studentId): array
    {
        $student = $this->getStudent($studentId);
        foreach ($this->listFileModels() as $file) {
            if (($file->student ?? '') !== $studentId) {
                continue;
            }
            $file->student = null;
            $file->saveAttributes();
        }
        $student->delete();
        $this->save();
        return $this->list_files();
    }

    /**
     * Update type and/or student assignment for an existing file.
     *
     * @param string|null $student Student hash, empty string to unassign, or null to leave unchanged
     * @return array Updated file list
     */
    public function setFileTags(string $fileId, ?string $type, ?string $student): array
    {
        $file = $this->getFile($fileId);

        $nextType = $file->type;
        if ($type !== null) {
            if ($type === 'unknown') {
                $type = '';
            }
            if ($type !== '' && !in_array($type, static::FILE_TYPES, true)) {
                throw new WSException('Invalid file type', 400);
            }
            $nextType = $type;
        }

        $nextStudent = $file->student;
        if ($student !== null) {
            $trimmed = trim($student);
            if ($trimmed === '') {
                $nextStudent = null;
            } else {
                $this->getStudent($trimmed);
                $nextStudent = $trimmed;
            }
        }

        $blobNow = $file instanceof S3File;
        $blobNext = S3File::storesAsBlob($nextType);
        if ($blobNow && !$blobNext && $file instanceof S3File) {
            $this->promoteBlob($file, $nextType, $nextStudent);
        } elseif (!$blobNow && $blobNext && $file instanceof InputFile) {
            $this->demoteInput($file, $nextType, $nextStudent);
        } else {
            $file->type = $nextType;
            $file->student = $nextStudent;
            if ($file instanceof InputFile) {
                $expected = $this->classForInputType($nextType);
                if ($file::class !== $expected) {
                    $file = $file->asClass($expected);
                }
            }
            $file->saveAttributes();
        }
        return $this->list_files();
    }

    /**
     * Create a new file with optional type and student hash.
     *
     * @return array Updated file list
     */
    public function createFile(
        string $filename,
        string $content,
        string $contentType,
        ?string $type,
        ?string $student
    ): array {
        $this->createFileModel($filename, $content, $contentType, $type, $student);
        return $this->list_files();
    }

    /**
     * Create a file and return the model (used by pipelines that need the new id).
     */
    public function createFileModel(
        string $filename,
        string $content,
        string $contentType,
        ?string $type,
        ?string $student,
        bool $enqueue = true
    ): InputFile|S3File {
        $converted = $this->heicStoredAsWebp($filename, $type, $content);
        if ($converted !== null) {
            $filename = $converted['filename'];
            $content = $converted['bytes'];
            $contentType = $converted['contentType'];
        }
        [$filename, $type, $studentHash] = $this->prepareNewFile($filename, $type, $student);
        $contentType = $contentType !== '' ? $contentType : 'application/octet-stream';
        if (S3File::storesAsBlob($type)) {
            return $this->storeNewBlob($filename, $contentType, $type, $studentHash, strlen($content), null, $content);
        }
        $file = $this->newInputFile($filename, $contentType, $type, $studentHash, strlen($content));
        $this->storeNewInput($file, null, $content, $enqueue);
        return $file;
    }

    /**
     * Upload from a local path (multipart upload).
     *
     * Subject intake stores the files it is still reading with $enqueue false.
     * Files added once the subject is known use the default and join the queue.
     */
    public function createFileFromPath(
        string $filename,
        string $localPath,
        ?string $contentType,
        ?string $type,
        ?string $student,
        bool $enqueue = true
    ): InputFile|S3File {
        $head = file_get_contents($localPath, false, null, 0, 264);
        if (is_string($head) && HeicToWebp::isHeif($head)) {
            $bytes = file_get_contents($localPath);
            if ($bytes === false || $bytes === '') {
                throw new WSException('Failed to read uploaded file', 400);
            }
            $converted = $this->heicStoredAsWebp($filename, $type, $bytes);
            if ($converted !== null) {
                return $this->createFileModel(
                    $converted['filename'],
                    $converted['bytes'],
                    $converted['contentType'],
                    $type,
                    $student,
                    $enqueue
                );
            }
        }
        [$filename, $type, $studentHash] = $this->prepareNewFile($filename, $type, $student);
        $size = filesize($localPath);
        if ($size === false) {
            throw new WSException('Failed to read uploaded file size', 400);
        }
        $resolvedType = ($contentType !== null && $contentType !== '')
            ? $contentType
            : 'application/octet-stream';
        if (S3File::storesAsBlob($type)) {
            return $this->storeNewBlob($filename, $resolvedType, $type, $studentHash, (int) $size, $localPath, null);
        }
        $file = $this->newInputFile($filename, $resolvedType, $type, $studentHash, (int) $size);
        $this->storeNewInput($file, $localPath, null, $enqueue);
        return $file;
    }

    /**
     * Build a File model from S3 attribute payload for this assessment.
     */
    public function fileFromAttributes(array $data, string $fileId, ?string $etag = null): InputFile
    {
        $type = (string) ($data['type'] ?? '');
        $class = InputFile::classFromPayload($data);
        if ($class === null) {
            $class = S3File::storesAsBlob($type) ? $this->fileClass() : $this->classForInputType($type);
        }
        if (!InputFile::isFileClass($class)) {
            $class = SubmissionFile::class;
        }
        $file = $class::from_array($data);
        $file->id = $fileId;
        $file->school_id = $this->school_id;
        $file->user_id = $this->user_id;
        $file->assessment_id = $this->id ?? '';
        $file->etag = $etag;
        return $file;
    }

    public function uniqueDisplayName(string $filename): string
    {
        if ($filename === '' || preg_match('/[\/\\\\]/', $filename)) {
            throw new WSException('Invalid file name', 400);
        }

        $existing = [];
        foreach ($this->listFileModels() as $file) {
            $existing[strtolower($file->name)] = true;
        }
        if (!isset($existing[strtolower($filename)])) {
            return $filename;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $index = 1;
        do {
            $candidate = $extension === ''
                ? $base . '_' . $index
                : $base . '_' . $index . '.' . $extension;
            $index++;
        } while (isset($existing[strtolower($candidate)]));

        return $candidate;
    }

    /**
     * Overwrite the body of an existing file.
     *
     * @return array Updated file list
     */
    public function writeFileContents(string $fileId, string $content, ?string $contentType = null): array
    {
        $file = $this->getFile($fileId);
        $type = $contentType ?? $file->content_type;
        if (HeicToWebp::isHeif($content)) {
            try {
                $content = (new HeicToWebp($content))->webp;
                $type = 'image/webp';
            } catch (\Throwable $exception) {
                throw new WSException('Cannot convert HEIC image', 400, $exception);
            }
        }
        if ($type === '') {
            $type = 'text/plain; charset=utf-8';
        }
        $file->size = strlen($content);
        $file->content_type = $type;
        if ($file instanceof S3File) {
            $file->putContents($content, $type);
            ObjectStore::getInstance()->setIdPointer($file->id, $file->key, false);
        } else {
            ObjectStore::getInstance()->putContents($file->contentKey(), $content, $type);
            $file->saveAttributes();
        }
        return $this->list_files();
    }

    /**
     * Rename a file's display name (content key stays put).
     *
     * @return array Updated file list
     */
    public function renameFile(string $fileId, string $newName): array
    {
        $newName = trim($newName);
        if ($newName === '' || preg_match('/[\/\\\\]/', $newName)) {
            throw new WSException('Invalid file name', 400);
        }

        $file = $this->getFile($fileId);
        if ($file->name === $newName) {
            return $this->list_files();
        }

        foreach ($this->listFileModels() as $other) {
            if ($other->id !== $file->id && strcasecmp($other->name, $newName) === 0) {
                throw new WSException("File '$newName' already exists", 409);
            }
        }

        $file->name = $newName;
        $file->saveAttributes();
        return $this->list_files();
    }

    /**
     * Delete files of one type for one student hash.
     */
    public function deleteFilesOfType(string $type, string $student): void
    {
        foreach ($this->listFileModels() as $file) {
            if ($file->type !== $type) {
                continue;
            }
            $fileStudent = $file->student ?? '';
            if ($fileStudent !== $student) {
                continue;
            }
            $file->delete();
        }
        if ($type === 'correction' && $student !== '') {
            $correctionKey = ObjectStore::assessmentStudentCorrectionKey(
                $this->school_id,
                $this->user_id,
                $this->id,
                $student
            );
            $store = ObjectStore::getInstance();
            if ($store->exists($correctionKey)) {
                try {
                    $head = $store->head($correctionKey);
                    $meta = S3File::normalizeMetadata($head['Metadata'] ?? []);
                    if (isset($meta['id']) && $meta['id'] !== '') {
                        $store->deleteIdPointer($meta['id']);
                    }
                } catch (\Throwable $e) {
                }
                $store->delete($correctionKey);
            }
        }
    }

    /**
     * Delete a file by hash.
     *
     * @return array Updated file list
     */
    public function deleteFile(string $fileId): array
    {
        $file = $this->getFile($fileId);
        $file->delete();
        return $this->list_files();
    }

    /**
     * Entry task class for this assessment's subject. Unknown subjects use Other.
     *
     * @return class-string
     */
    public function pipelineClass(): string
    {
        return Catalog::pipelineClass($this->subject, $this->country ?? '', $this->level ?? '');
    }

    /**
     * Grade a submission through the subject file status machine.
     *
     * @return array Updated file list
     */
    public function correctSubmission(string $fileId): array
    {
        $file = $this->getFile($fileId);
        if (!$file instanceof InputFile || $file->type !== 'submission') {
            throw new WSException('File is not a submission', 400);
        }

        $store = ObjectStore::getInstance();
        if (!$store->exists($file->contentKey())) {
            throw new WSException("File '$fileId' does not exist for assessment {$this->id}", 404);
        }

        $subjectFileClass = $this->fileClass();
        if ($subjectFileClass !== $file::class) {
            $file = $file->asClass($subjectFileClass);
            $file->saveAttributes();
        }

        $method = new \ReflectionMethod($file, 'on_correction_asked');
        if ($method->getDeclaringClass()->getName() !== InputFile::class) {
            $file->appendEvent('Correction started');
            $file->status = 'correction_asked';
            $file->saveAttributes();
            $file->on_correction_asked();
            RedisQueue::getInstance()->enqueueFile($file->id, $this->pipelineClass());
            return $this->list_files();
        }

        throw new WSException('This subject has no correction task', 400);
    }

    /**
     * File ids under unclassified/ that correctUnclassifiedFiles() would process.
     *
     * @return string[]
     */
    public function unclassifiedFileIds(): array
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '' || $this->user_id === '') {
            return [];
        }

        $store = ObjectStore::getInstance();
        $prefix = ObjectStore::assessmentUnclassifiedFilesPrefix($this->school_id, $this->user_id, $this->id);
        $ids = [];
        foreach ($store->listChildPrefixes($prefix) as $fileId) {
            $attrKey = $prefix . $fileId . '/' . ObjectStore::ATTR_FILE;
            if ($store->exists($attrKey)) {
                $ids[] = $fileId;
            }
        }
        return $ids;
    }

    /**
     * Copies billed by hosted checkout. Matches what startCorrection() processes.
     */
    public function pricedCopyCount(): int
    {
        return count($this->unclassifiedFileIds());
    }

    /**
     * Start correction for every unclassified copy.
     *
     * Checkout calls this after payment, and also when the batch is free.
     * The set of copies does not depend on whether a charge was made.
     * A single-copy trial is testCorrection().
     *
     * @return array Updated file list
     */
    public function startCorrection(): array
    {
        return $this->correctUnclassifiedFiles();
    }

    /**
     * Start correction for the first unclassified file, or first submission.
     *
     * @return array Updated file list
     */
    public function correctFirstCopy(): array
    {
        $unclassifiedIds = $this->unclassifiedFileIds();
        if ($unclassifiedIds !== []) {
            return $this->correctUnclassifiedFiles([$unclassifiedIds[0]]);
        }
        foreach ($this->listFileModels() as $file) {
            if ($file->type === 'submission' && $file->id !== null && $file->id !== '') {
                return $this->correctSubmission($file->id);
            }
        }
        throw new WSException('No copies to correct', 400);
    }

    /**
     * Start correction for one copy, without checkout. Used from the test-correction button.
     *
     * The paid or free full launch is startCorrection(), which processes every copy.
     *
     * @return array Updated file list
     */
    public function testCorrection(): array
    {
        return $this->correctFirstCopy();
    }

    /**
     * Start correction for files stored under unclassified/.
     * When $onlyFileIds is provided, only those file IDs are processed.
     *
     * A stored class is kept. A file with no class becomes submissionClass().
     *
     * @param string[]|null $onlyFileIds Optional subset of file IDs to correct
     * @return array Updated file list
     */
    public function correctUnclassifiedFiles(?array $onlyFileIds = null): array
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '' || $this->user_id === '') {
            throw new WSException('Assessment id, school_id and user_id are required', 400);
        }

        $fallback = $this->submissionClass();
        if (!InputFile::isFileClass($fallback)) {
            $fallback = SubmissionFile::class;
        }

        $store = ObjectStore::getInstance();
        $prefix = ObjectStore::assessmentUnclassifiedFilesPrefix($this->school_id, $this->user_id, $this->id);
        $allowed = $onlyFileIds !== null ? array_flip($onlyFileIds) : null;
        foreach ($store->listChildPrefixes($prefix) as $fileId) {
            if ($allowed !== null && !isset($allowed[$fileId])) {
                continue;
            }
            $attrKey = $prefix . $fileId . '/' . ObjectStore::ATTR_FILE;
            if (!$store->exists($attrKey)) {
                continue;
            }

            $loaded = $store->getJson($attrKey);
            $data = $loaded['data'];
            $storedClass = InputFile::classFromPayload($data);
            $class = $storedClass ?? $fallback;
            $file = $class::from_array($data);
            $file->id = $fileId;
            $file->school_id = $this->school_id;
            $file->user_id = $this->user_id;
            $file->assessment_id = $this->id;
            $file->etag = $loaded['etag'];

            if ($storedClass === null) {
                $file->saveAttributes();
            }

            $file->on_correction_asked();
        }

        return $this->list_files();
    }

    public function instructionFilesText(): string
    {
        $store = ObjectStore::getInstance();
        $parts = [];
        foreach ($this->listFileModels() as $file) {
            if ($file->type !== 'instructions') {
                continue;
            }
            $parts[] = $file->name . ":\n" . $store->getContents($file->contentKey());
        }
        return implode("\n\n", $parts);
    }

    /**
     * Resolves the template instruction file for this assessment's subject.
     * Searches for "template_instruction_{locale}.md" first, and falls back to "template_instruction.md"
     * (also supporting plural "template_instructions*.md" filenames).
     */
    public function templateInstructionPath(?string $locale = null): ?string
    {
        $dir = dirname((new \ReflectionClass($this))->getFileName());
        $locale = $locale !== null && trim($locale) !== '' ? trim($locale) : $this->correction_language;
        $locales = [];
        $raw = strtolower(trim($locale));
        if ($raw !== '') {
            $locales[] = $raw;
            $dash = strpos($raw, '-');
            if ($dash !== false) {
                $locales[] = substr($raw, 0, $dash);
            }
            $underscore = strpos($raw, '_');
            if ($underscore !== false) {
                $locales[] = substr($raw, 0, $underscore);
            }
        }
        $locales = array_unique(array_filter($locales));

        $candidates = [];
        foreach ($locales as $loc) {
            $candidates[] = $dir . '/template_instruction_' . $loc . '.md';
            $candidates[] = $dir . '/template_instructions_' . $loc . '.md';
        }
        $candidates[] = $dir . '/template_instruction.md';
        $candidates[] = $dir . '/template_instructions.md';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Creates the assessment's instruction file from the template file if one exists.
     */
    public function createInstructionFileFromTemplate(?string $locale = null): ?InputFile
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '' || $this->user_id === '') {
            return null;
        }

        $path = $this->templateInstructionPath($locale);
        if ($path === null) {
            return null;
        }

        foreach ($this->listFileModels() as $file) {
            if ($file->type === 'instructions') {
                return $file;
            }
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return $this->createFileModel(
            'instructions.md',
            $content,
            'text/markdown',
            'instructions',
            null
        );
    }

    /**
     * All uploaded HEIC images are converted and stored as WebP.
     *
     * @return array{filename: string, contentType: string, bytes: string}|null
     */
    private function heicStoredAsWebp(string $filename, ?string $type, string $bytes): ?array
    {
        if (!HeicToWebp::isHeif($bytes)) {
            return null;
        }

        try {
            $webp = (new HeicToWebp($bytes))->webp;
        } catch (\Throwable $exception) {
            throw new WSException('Cannot convert HEIC image', 400, $exception);
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);
        return [
            'filename' => ($base !== '' ? $base : 'image') . '.webp',
            'contentType' => 'image/webp',
            'bytes' => $webp,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function prepareNewFile(string $filename, ?string $type, ?string $student): array
    {
        $studentHash = null;
        if ($student !== null && trim($student) !== '') {
            $this->getStudent(trim($student));
            $studentHash = trim($student);
        }
        if ($filename !== 'correction.png' || $studentHash === null) {
            $filename = $this->uniqueDisplayName($filename);
        }
        if ($type === 'unknown') {
            $type = '';
        }
        $type = $type ?? '';
        if ($type !== '' && !in_array($type, static::FILE_TYPES, true)) {
            throw new WSException('Invalid file type', 400);
        }
        return [$filename, $type, $studentHash];
    }

    /**
     * @return class-string<InputFile>
     */
    private function classForInputType(string $type): string
    {
        if ($type === 'subject') {
            return SubjectFile::class;
        }
        if ($type === 'instructions') {
            return InstructionFile::class;
        }
        $class = $this->submissionClass();
        if (!InputFile::isFileClass($class)) {
            return SubmissionFile::class;
        }
        return $class;
    }

    private function newInputFile(
        string $filename,
        string $contentType,
        string $type,
        ?string $student,
        int $size
    ): InputFile {
        $class = $this->classForInputType($type);
        $file = new $class();
        $file->id = HashId::create();
        $file->school_id = $this->school_id;
        $file->user_id = $this->user_id;
        $file->assessment_id = $this->id;
        $file->name = $filename;
        $file->type = $type;
        $file->student = $student;
        $file->status = 'stored';
        $file->content_type = $contentType;
        $file->size = $size;
        $file->created = time();
        return $file;
    }

    private function storeNewInput(InputFile $file, ?string $localPath, ?string $bytes, bool $enqueue = true): void
    {
        $store = ObjectStore::getInstance();
        if ($localPath !== null) {
            $store->put($file->contentKey(), $localPath, $file->content_type);
        } else {
            $store->putContents($file->contentKey(), (string) $bytes, $file->content_type);
        }
        $file->saveAttributes(false);
        $file->appendEvent('Stored');
        if ($enqueue && $file->type === 'subject' && SubjectPages::isPageImage($file)) {
            SubjectPages::request($file);
            return;
        }
        if ($enqueue) {
            RedisQueue::getInstance()->enqueueFile($file->id, $this->pipelineClass());
        }
    }

    private function storeNewBlob(
        string $filename,
        string $contentType,
        string $type,
        ?string $student,
        int $size,
        ?string $localPath,
        ?string $bytes
    ): S3File {
        $blob = new S3File();
        $blob->id = HashId::create();
        $blob->school_id = $this->school_id;
        $blob->user_id = $this->user_id;
        $blob->assessment_id = $this->id ?? '';
        $blob->name = $filename;
        $blob->type = $type;
        $blob->student = $student;
        $blob->content_type = $contentType;
        $blob->size = $size;
        $blob->created = time();
        if ($student !== null && trim($student) !== '' && $filename === 'correction.png') {
            $blob->key = ObjectStore::assessmentStudentCorrectionKey(
                $this->school_id,
                $this->user_id,
                (string) $this->id,
                trim($student)
            );
        } else {
            $blob->key = ObjectStore::assessmentBlobKey(
                $this->school_id,
                $this->user_id,
                (string) $this->id,
                $blob->id
            );
        }
        $store = ObjectStore::getInstance();
        if ($store->exists($blob->key)) {
            try {
                $oldHead = $store->head($blob->key);
                $oldMeta = S3File::normalizeMetadata($oldHead['Metadata'] ?? []);
                if (isset($oldMeta['id']) && $oldMeta['id'] !== '') {
                    $store->deleteIdPointer($oldMeta['id']);
                }
            } catch (\Throwable $e) {
            }
        }
        if ($localPath !== null) {
            $blob->etag = $store->put($blob->key, $localPath, $blob->content_type, null, $blob->userMetadata());
        } else {
            $blob->etag = $store->putContents(
                $blob->key,
                (string) $bytes,
                $blob->content_type,
                null,
                $blob->userMetadata()
            );
        }
        $store->setIdPointer($blob->id, $blob->key, false);
        return $blob;
    }

    private function promoteBlob(S3File $blob, string $type, ?string $student): InputFile
    {
        $store = ObjectStore::getInstance();
        $bytes = $store->getContents($blob->key);
        $class = $this->classForInputType($type);
        $file = new $class();
        $file->id = $blob->id;
        $file->school_id = $blob->school_id;
        $file->user_id = $blob->user_id;
        $file->assessment_id = $blob->assessment_id;
        $file->name = $blob->name;
        $file->type = $type;
        $file->student = $student;
        $file->status = 'stored';
        $file->content_type = $blob->content_type;
        $file->size = strlen($bytes);
        $file->created = $blob->created > 0 ? $blob->created : time();
        $store->putContents($file->contentKey(), $bytes, $file->content_type);
        $file->saveAttributes(false);
        $file->appendEvent('Stored');
        if ($blob->key !== '' && $store->exists($blob->key)) {
            $store->delete($blob->key);
        }
        RedisQueue::getInstance()->enqueueFile($file->id, $this->pipelineClass());
        return $file;
    }

    private function demoteInput(InputFile $file, string $type, ?string $student): S3File
    {
        $store = ObjectStore::getInstance();
        $bytes = $store->getContents($file->contentKey());
        $blob = new S3File();
        $blob->id = $file->id;
        $blob->school_id = $file->school_id;
        $blob->user_id = $file->user_id;
        $blob->assessment_id = $file->assessment_id;
        $blob->name = $file->name;
        $blob->type = $type;
        $blob->student = $student;
        $blob->content_type = $file->content_type;
        $blob->size = strlen($bytes);
        $blob->created = $file->created > 0 ? $file->created : time();
        if ($student !== null && trim($student) !== '' && $blob->name === 'correction.png') {
            $blob->key = ObjectStore::assessmentStudentCorrectionKey(
                $file->school_id,
                $file->user_id,
                $file->assessment_id,
                trim($student)
            );
        } else {
            $blob->key = ObjectStore::assessmentBlobKey(
                $file->school_id,
                $file->user_id,
                $file->assessment_id,
                (string) $file->id
            );
        }
        $store->putContents($blob->key, $bytes, $blob->content_type, null, $blob->userMetadata());
        $store->setIdPointer((string) $blob->id, $blob->key, false);
        $store->deletePrefix($file->prefix());
        return $blob;
    }
}
