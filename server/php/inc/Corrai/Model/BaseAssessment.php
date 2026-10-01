<?php

namespace Corrai\Model;

use Exception;
use Corrai\Llm\Openrouter\LlmClientFactory;
use Corrai\Queue\RedisQueue;
use Corrai\Utils\HashId;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Corrai\Subject\Catalog;
use Corrai\Subject\Dictation\Pipeline as Dictation;
use Corrai\Subject\AssessmentFactory;
use Corrai\Subject\Law\Pipeline as Law;
use Corrai\Subject\Math\Pipeline as MathPipeline;
use Corrai\Subject\Other\Pipeline as Other;
use Corrai\Subject\Physics\Pipeline as Physics;

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
     * Pipeline name for this assessment (MathPipeline, Physics, Dictation, Law, Other).
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
     * Pipeline name => pipeline class. Unknown subjects use Other.
     *
     * @var array<string, class-string>
     */
    public const SUBJECT_PIPELINES = [
        'MathPipeline' => MathPipeline::class,
        'Physics' => Physics::class,
        'Dictation' => Dictation::class,
        'Law' => Law::class,
        'Other' => Other::class,
    ];

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
     * Load an Assessment from its hash via the _id pointer.
     */
    public static function from_hash(string $hash): static
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
        $assessment = static::from_array($loaded['data']);
        $assessment->id = $assessmentId;
        $assessment->school_id = $schoolId;
        $assessment->user_id = $userId;
        return $assessment;
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

    public function to_output(): array
    {
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
        $store->putJson(
            ObjectStore::assessmentAttrKey($this->school_id, $this->user_id, $this->id),
            [
                'name' => $this->name,
                'subject' => $this->subject,
                'country' => self::optionalAttribute($this->country),
                'level' => self::optionalAttribute($this->level),
                'date' => $this->date,
                'created_at' => $this->created_at,
            ]
        );
        $store->setIdPointer(
            $this->id,
            ObjectStore::assessmentPrefix($this->school_id, $this->user_id, $this->id)
        );
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
    public function list_files(): array
    {
        $names = $this->studentNameMap();
        $out = [];
        foreach ($this->listFileModels() as $file) {
            $studentName = null;
            if ($file->student !== null && isset($names[$file->student])) {
                $studentName = $names[$file->student];
            }
            $out[] = $file->to_output($studentName);
        }
        return $out;
    }

    /**
     * @return array Array of student output arrays
     */
    public function list_students(): array
    {
        $out = [];
        foreach ($this->listStudentModels() as $student) {
            $out[] = $student->to_output();
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
        $file->status = 'loaded';
        $file->content_type = $contentType !== '' ? $contentType : 'application/octet-stream';
        $file->size = strlen($content);
        $file->created = time();

        $store = ObjectStore::getInstance();
        $store->putContents($file->contentKey(), $content, $file->content_type);
        $file->saveAttributes(false);
        $file->appendEvent('Loaded');
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
        $file->status = 'loaded';
        $file->content_type = ($contentType !== null && $contentType !== '')
            ? $contentType
            : 'application/octet-stream';
        $file->size = (int) $size;
        $file->created = time();

        $store = ObjectStore::getInstance();
        $store->put($file->contentKey(), $localPath, $file->content_type);
        $file->saveAttributes(false);
        $file->appendEvent('Loaded');
        RedisQueue::getInstance()->enqueueFile($file->id);

        return $file;
    }

    /**
     * Build a File model from S3 attribute payload for this assessment.
     */
    public function fileFromAttributes(array $data, string $fileId, ?string $etag = null): File
    {
        $class = $this->fileClass();
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
     * Answer assessment questions from the attached files via the LLM client.
     *
     * @param array $questions List of question arrays with a 'text' key, or plain strings
     */
    public function verify(array $questions = []): array
    {
        $request = LlmClientFactory::create($_ENV['OPENROUTER_MODEL'] ?? null);
        $system = <<<EOT
            # SYSTEM:
            You are a file data analyser.
            Parse the given files and answer the given questions by user.
            Answer the given questions under the form of a JSON array with the answers in the same order as the questions.
            Returns ONLY a valid JSON respecting SCHEMA_STRICT below with NO extra comment text.
            SCHEMA_STRICT:
            { responses: [
                { response: boolean, confidence: float between 0 and 1, explanation: "string, in the same language as the question" }
            ]}
            If looking for numbers, make sure to read the number linked to the question.
            Use equivalence beween currency symbols dans abrevations like "USD" and "$" and "EUR" and "€".
            Analyse the whole file content to answer the questions and do not make assumptions. Do not use information from other questions to answer the current question.
            When reading a number or a date, use the text context to link it to the question.
            Use the number format and the date format of the langage of the file.
        EOT;

        $request->set_system_content($system);
        $store = ObjectStore::getInstance();
        $tempFiles = [];
        try {
            foreach ($this->listFileModels() as $file) {
                $tmpPath = $store->downloadToTemp($file->contentKey());
                $tempFiles[] = $tmpPath;
                $request->add_file($tmpPath, $file->name);
            }

            $instruction = '';
            $question_index = 0;
            foreach ($questions as $question) {
                $text = is_array($question) ? ($question['text'] ?? '') : (string) $question;
                $instruction .= 'Question ' . $question_index . ': ' . $text . "\n";
                $question_index++;
            }

            $request->add_text($instruction);
            return $request->call();
        } finally {
            foreach ($tempFiles as $tmpPath) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Pipeline class for this assessment's subject. Unknown subjects use Other.
     *
     * @return class-string
     */
    public function pipelineClass(): string
    {
        return Catalog::pipelineClass($this->subject, $this->country ?? '', $this->level ?? '');
    }

    /**
     * Grade a submission with the pipeline stored for the assessment subject.
     *
     * When the file class implements on_correction_asked, correction runs
     * asynchronously through the Redis status machine instead of blocking
     * the HTTP request.
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

        if (method_exists($file, 'on_correction_asked')) {
            $file->appendEvent('Correction started');
            $file->status = 'correction_asked';
            $file->saveAttributes();
            RedisQueue::getInstance()->enqueueFile($file->id);
            return $this->list_files();
        }

        $file->appendEvent('Correction started');
        $file->status = 'correcting';
        $file->saveAttributes();

        try {
            $class = $this->pipelineClass();
            $result = (new $class())->run($this, $fileId, $language);
            $file = $this->getFile($fileId);
            $file->appendEvent('Correction ended');
            $file->status = 'corrected';
            $file->saveAttributes();
            return $result;
        } catch (\Throwable $e) {
            try {
                $file = $this->getFile($fileId);
                $file->appendEvent('Correction failed');
                $file->status = 'error';
                $file->saveAttributes();
            } catch (\Throwable $ignore) {
                // Best-effort status update
            }
            throw $e;
        }
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
