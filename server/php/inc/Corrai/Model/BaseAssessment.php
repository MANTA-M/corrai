<?php

namespace Corrai\Model;

use Exception;
use Corrai\Llm\Openrouter\LlmClientFactory;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\HashId;
use Corrai\Utils\MenuLabels;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Corrai\Subject\Catalog;
use Corrai\Subject\Dictation\CorrectingTask as Dictation;
use Corrai\Subject\AssessmentFactory;
use Corrai\Subject\Law\TranscribingTask as Law;
use Corrai\Subject\Math\TranscribingTask as MathPipeline;
use Corrai\Subject\Other\TranscribingTask as Other;
use Corrai\Subject\Physics\TranscribingTask as Physics;

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
     * The date of the assessment (YYYY-MM-DD).
     */
    public string $date = '';

    /**
     * ISO-8601 creation timestamp.
     */
    public string $created_at = '';

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
     * @return class-string<File>
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

        return File::class;
    }

    /**
     * File class used for submissions that have no stored class.
     *
     * Generic Assessment instances resolve via AssessmentFactory, same as fileClass().
     *
     * @return class-string<File>
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

        return File::class;
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
            'date' => $this->date,
            'created_at' => $this->created_at,
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
        $assessment->date = $data['date'] ?? '';
        $assessment->created_at = $data['created_at'] ?? '';
        return $assessment;
    }

    /**
     * Country and level are null when the client sends null, omits them, or sends a blank string.
     */
    public static function optionalAttribute(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
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
            'date' => $this->date,
            'created_at' => $this->created_at,
            'label' => $this->localizedLabel($locale),
            'menu' => $this->get_menu($locale),
        ];
    }

    /**
     * Persist assessment attributes.json and register the _id pointer.
     */
    public function save(): void
    {
        if ($this->id === null || $this->id === '') {
            $this->id = HashId::create();
        }
        if ($this->created_at === '') {
            $this->created_at = gmdate('c');
        }

        $this->validate();

        $store = ObjectStore::getInstance();
        $attrKey = ObjectStore::assessmentAttrKey($this->school_id, $this->user_id, $this->id);
        $previousClass = null;
        if ($store->exists($attrKey)) {
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
    }

    /**
     * Rewrite file attribute documents so their class matches this assessment.
     */
    private function syncFileClasses(): void
    {
        $expected = $this->fileClass();
        if (!BaseFile::isFileClass($expected)) {
            return;
        }
        foreach ($this->listFileModels() as $file) {
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
     * @return File[]
     */
    public function listFileModels(): array
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            return [];
        }

        $files = [];
        $seen = [];
        $class = $this->fileClass();
        foreach ($this->storedFileIds() as $fileId) {
            if (isset($seen[$fileId])) {
                continue;
            }
            $seen[$fileId] = true;
            try {
                $files[] = $class::from_hash($fileId);
            } catch (\Exception $e) {
                continue;
            }
        }
        return $files;
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

    public function getFile(string $fileId): File
    {
        $class = $this->fileClass();
        $file = $class::from_hash($fileId);
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
        $student->save(false);
        return $student;
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

        if ($type !== null) {
            if ($type === 'unknown') {
                $type = '';
            }
            if ($type !== '' && !in_array($type, static::FILE_TYPES, true)) {
                throw new WSException('Invalid file type', 400);
            }
            $file->type = $type;
        }
        if ($student !== null) {
            $trimmed = trim($student);
            if ($trimmed === '') {
                $file->student = null;
            } else {
                $this->getStudent($trimmed);
                $file->student = $trimmed;
            }
        }

        $file->saveAttributes();
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
        ?string $student
    ): File {
        $filename = $this->uniqueDisplayName($filename);
        if ($type === 'unknown') {
            $type = '';
        }
        if ($type !== null && $type !== '' && !in_array($type, static::FILE_TYPES, true)) {
            throw new WSException('Invalid file type', 400);
        }
        $studentHash = null;
        if ($student !== null && trim($student) !== '') {
            $this->getStudent(trim($student));
            $studentHash = trim($student);
        }

        $class = $this->fileClass();
        $file = new $class();
        $file->id = HashId::create();
        $file->school_id = $this->school_id;
        $file->user_id = $this->user_id;
        $file->assessment_id = $this->id;
        $file->name = $filename;
        $file->type = $type ?? '';
        $file->student = $studentHash;
        $file->status = 'stored';
        $file->content_type = $contentType !== '' ? $contentType : 'application/octet-stream';
        $file->size = strlen($content);
        $file->created = time();

        $store = ObjectStore::getInstance();
        $store->putContents($file->contentKey(), $content, $file->content_type);
        $file->saveAttributes(false);
        $file->appendEvent('Stored');
        RedisQueue::getInstance()->enqueueFile($file->id);

        return $file;
    }

    /**
     * Upload from a local path (multipart upload).
     */
    public function createFileFromPath(
        string $filename,
        string $localPath,
        ?string $contentType,
        ?string $type,
        ?string $student
    ): File {
        $filename = $this->uniqueDisplayName($filename);
        if ($type === 'unknown') {
            $type = '';
        }
        if ($type !== null && $type !== '' && !in_array($type, static::FILE_TYPES, true)) {
            throw new WSException('Invalid file type', 400);
        }
        $studentHash = null;
        if ($student !== null && trim($student) !== '') {
            $this->getStudent(trim($student));
            $studentHash = trim($student);
        }

        $size = filesize($localPath);
        if ($size === false) {
            throw new WSException('Failed to read uploaded file size', 400);
        }

        $class = $this->fileClass();
        $file = new $class();
        $file->id = HashId::create();
        $file->school_id = $this->school_id;
        $file->user_id = $this->user_id;
        $file->assessment_id = $this->id;
        $file->name = $filename;
        $file->type = $type ?? '';
        $file->student = $studentHash;
        $file->status = 'stored';
        $file->content_type = ($contentType !== null && $contentType !== '')
            ? $contentType
            : 'application/octet-stream';
        $file->size = (int) $size;
        $file->created = time();

        $store = ObjectStore::getInstance();
        $store->put($file->contentKey(), $localPath, $file->content_type);
        $file->saveAttributes(false);
        $file->appendEvent('Stored');
        RedisQueue::getInstance()->enqueueFile($file->id);

        return $file;
    }

    /**
     * Build a File model from S3 attribute payload for this assessment.
     */
    public function fileFromAttributes(array $data, string $fileId, ?string $etag = null): File
    {
        $class = BaseFile::classFromPayload($data) ?? $this->fileClass();
        if (!BaseFile::isFileClass($class)) {
            $class = File::class;
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
        if ($type === '') {
            $type = 'text/plain; charset=utf-8';
        }
        ObjectStore::getInstance()->putContents($file->contentKey(), $content, $type);
        $file->size = strlen($content);
        $file->content_type = $type;
        $file->saveAttributes();
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
    public function correctSubmission(string $fileId, string $language): array
    {
        $file = $this->getFile($fileId);
        if ($file->type !== 'submission') {
            throw new WSException('File is not a submission', 400);
        }

        $store = ObjectStore::getInstance();
        if (!$store->exists($file->contentKey())) {
            throw new WSException("File '$fileId' does not exist for assessment {$this->id}", 404);
        }

        $store->putContents(
            rtrim($file->prefix(), '/') . '/correction_language.txt',
            $language,
            'text/plain; charset=utf-8'
        );

        $subjectFileClass = $this->fileClass();
        if ($subjectFileClass !== $file::class) {
            $file = $file->asClass($subjectFileClass);
            $file->saveAttributes();
        }

        $method = new \ReflectionMethod($file, 'on_correction_asked');
        if ($method->getDeclaringClass()->getName() !== BaseFile::class) {
            $file->appendEvent('Correction started');
            $file->status = 'correction_asked';
            $file->saveAttributes();
            $file->on_correction_asked();
            RedisQueue::getInstance()->enqueueFile($file->id);
            return $this->list_files();
        }

        throw new WSException('This subject has no correction task', 400);
    }

    /**
     * Start correction for every file stored under unclassified/.
     *
     * A stored class is kept. A file with no class becomes submissionClass().
     *
     * @return array Updated file list
     */
    public function correctUnclassifiedFiles(string $language): array
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '' || $this->user_id === '') {
            throw new WSException('Assessment id, school_id and user_id are required', 400);
        }

        $fallback = $this->submissionClass();
        if (!BaseFile::isFileClass($fallback)) {
            $fallback = File::class;
        }

        $store = ObjectStore::getInstance();
        $prefix = ObjectStore::assessmentUnclassifiedFilesPrefix($this->school_id, $this->user_id, $this->id);
        foreach ($store->listChildPrefixes($prefix) as $fileId) {
            $attrKey = $prefix . $fileId . '/' . ObjectStore::ATTR_FILE;
            if (!$store->exists($attrKey)) {
                continue;
            }

            $loaded = $store->getJson($attrKey);
            $data = $loaded['data'];
            $storedClass = BaseFile::classFromPayload($data);
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

            if ($store->exists($file->contentKey())) {
                $store->putContents(
                    rtrim($file->prefix(), '/') . '/correction_language.txt',
                    $language,
                    'text/plain; charset=utf-8'
                );
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
}
