<?php

namespace Corrai;

use Exception;
use Corrai\LlmClient\LlmClientFactory;

class Exam
{
    /**
     * The unique identifier of the exam (7-char hash).
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
     * The name of the exam.
     */
    public string $name = '';

    /**
     * The subject of the exam.
     */
    public string $subject = '';

    /**
     * The date of the exam (YYYY-MM-DD).
     */
    public string $date = '';

    /**
     * ISO-8601 creation timestamp.
     */
    public string $created_at = '';

    /**
     * Allowed file type tags. Empty / unknown is stored as an empty string.
     */
    public const FILE_TYPES = ['subject', 'solution', 'submission', 'instructions', 'correction'];

    public static function from_array(array $data): Exam
    {
        $exam = new Exam();
        $exam->id = $data['id'] ?? null;
        $exam->school_id = $data['school_id'] ?? '';
        $exam->user_id = $data['user_id'] ?? ($data['author'] ?? '');
        $exam->name = $data['name'] ?? '';
        $exam->subject = $data['subject'] ?? '';
        $exam->date = $data['date'] ?? '';
        $exam->created_at = $data['created_at'] ?? '';
        return $exam;
    }

    /**
     * Validate required fields. Throws WSException(400) on failure.
     */
    public function validate(): void
    {
        if (trim($this->school_id) === '') {
            throw new WSException('Exam school_id is required', 400);
        }
        if (trim($this->user_id) === '') {
            throw new WSException('Exam user_id is required', 400);
        }
        if (trim($this->name) === '') {
            throw new WSException('Exam name is required', 400);
        }
        if (trim($this->subject) === '') {
            throw new WSException('Exam subject is required', 400);
        }
        if (trim($this->date) === '') {
            throw new WSException('Exam date is required', 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            throw new WSException('Exam date must be YYYY-MM-DD', 400);
        }
        $parts = explode('-', $this->date);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw new WSException('Exam date is not a valid calendar date', 400);
        }
    }

    /**
     * Load an Exam from its hash via the _id pointer.
     */
    public static function from_hash(string $hash): Exam
    {
        $store = ObjectStore::getInstance();
        $prefix = $store->resolveIdPointer($hash);
        // prefix is {schoolId}/{userId}/{examId}/
        $parts = explode('/', trim($prefix, '/'));
        if (count($parts) < 3) {
            throw new Exception("Invalid exam path for hash $hash");
        }
        $schoolId = $parts[0];
        $userId = $parts[1];
        $examId = $parts[2];
        $csvKey = ObjectStore::examCsvKey($schoolId, $userId, $examId);

        if (!$store->exists($csvKey)) {
            throw new Exception("Exam with hash $hash does not exist");
        }

        $data = CsvStore::decode($store->getContents($csvKey));
        $exam = self::from_array($data);
        $exam->id = $examId;
        $exam->school_id = $schoolId;
        $exam->user_id = $userId;
        return $exam;
    }

    /**
     * List all exams owned by the given user (teacher).
     *
     * @return array Array of exam output arrays
     */
    public static function list_for_author(string $userId): array
    {
        $user = User::from_hash($userId);
        $exams = [];
        foreach ($user->exams() as $exam) {
            $exams[] = $exam->to_output();
        }
        return $exams;
    }

    public function to_output(): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'subject' => $this->subject,
            'date' => $this->date,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Persist exam.csv and register the _id pointer.
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
        $row = [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'subject' => $this->subject,
            'date' => $this->date,
            'created_at' => $this->created_at,
        ];
        $store->putContents(
            ObjectStore::examCsvKey($this->school_id, $this->user_id, $this->id),
            CsvStore::encode($row),
            'text/csv'
        );
        $store->setIdPointer(
            $this->id,
            ObjectStore::examPrefix($this->school_id, $this->user_id, $this->id)
        );
    }

    /**
     * Delete the exam prefix and its _id pointer.
     */
    public function delete(): void
    {
        if ($this->id === null || $this->id === '' || $this->school_id === '' || $this->user_id === '') {
            throw new Exception('Cannot delete exam without id, school_id and user_id');
        }

        $store = ObjectStore::getInstance();
        $store->deletePrefix(ObjectStore::examPrefix($this->school_id, $this->user_id, $this->id));
        $store->deleteIdPointer($this->id);
    }

    /**
     * S3 object key for an unassigned file belonging to this exam.
     */
    public function unassignedFileKey(string $filename): string
    {
        return ObjectStore::examUnassignedKey(
            $this->school_id,
            $this->user_id,
            $this->id,
            $filename
        );
    }

    /**
     * List all files in this exam's unassigned/ folder, merged with type/student tags.
     *
     * @return array Array of file information with name, size, created, type, and student
     */
    public function list_files(): array
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            return [];
        }

        $files = ObjectStore::getInstance()->list(
            ObjectStore::examUnassignedPrefix($this->school_id, $this->user_id, $this->id)
        );
        $tags = $this->loadFileTags();

        foreach ($files as &$file) {
            $name = $file['name'];
            $type = $tags[$name]['type'] ?? '';
            if (!in_array($type, self::FILE_TYPES, true)) {
                $type = '';
            }
            $file['type'] = $type;
            $file['student'] = $tags[$name]['student'] ?? '';
        }
        unset($file);

        return $files;
    }

    /**
     * Persist type and/or student tags for a file that already exists on this exam.
     *
     * @return array Updated file list
     */
    public function setFileTags(string $filename, ?string $type, ?string $student): array
    {
        $store = ObjectStore::getInstance();
        $key = $this->unassignedFileKey($filename);
        if (!$store->exists($key)) {
            throw new WSException("File '$filename' does not exist for exam {$this->id}", 404);
        }

        $tags = $this->loadFileTags();
        $current = $tags[$filename] ?? ['type' => '', 'student' => ''];

        if ($type !== null) {
            if ($type === 'unknown') {
                $type = '';
            }
            if ($type !== '' && !in_array($type, self::FILE_TYPES, true)) {
                throw new WSException('Invalid file type', 400);
            }
            $current['type'] = $type;
        }
        if ($student !== null) {
            $current['student'] = trim($student);
        }

        $tags[$filename] = $current;
        $this->saveFileTags($tags);

        return $this->list_files();
    }

    /**
     * Create a new unassigned file with optional type and student tags.
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
        $filename = $this->uniqueUnassignedName($filename);
        $store = ObjectStore::getInstance();
        $store->putContents($this->unassignedFileKey($filename), $content, $contentType);
        if ($type !== null || $student !== null) {
            $this->setFileTags($filename, $type, $student);
        }
        return $this->list_files();
    }

    public function uniqueUnassignedName(string $filename): string
    {
        if ($filename === '' || preg_match('/[\/\\\\]/', $filename)) {
            throw new WSException('Invalid file name', 400);
        }

        $store = ObjectStore::getInstance();
        if (!$store->exists($this->unassignedFileKey($filename))) {
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
        } while ($store->exists($this->unassignedFileKey($candidate)));

        return $candidate;
    }

    /**
     * Overwrite the body of an existing unassigned file.
     *
     * @return array Updated file list
     */
    public function writeFileContents(string $filename, string $content): array
    {
        if ($filename === '' || preg_match('/[\/\\\\]/', $filename)) {
            throw new WSException('Invalid file name', 400);
        }

        $store = ObjectStore::getInstance();
        $key = $this->unassignedFileKey($filename);
        if (!$store->exists($key)) {
            throw new WSException("File '$filename' does not exist for exam {$this->id}", 404);
        }

        $store->putContents($key, $content, 'text/plain; charset=utf-8');
        return $this->list_files();
    }

    /**
     * Rename an unassigned file and move its tags to the new name.
     *
     * @return array Updated file list
     */
    public function renameFile(string $oldName, string $newName): array
    {
        $newName = trim($newName);
        if ($newName === '' || preg_match('/[\/\\\\]/', $newName)) {
            throw new WSException('Invalid file name', 400);
        }

        $store = ObjectStore::getInstance();
        $oldKey = $this->unassignedFileKey($oldName);
        if (!$store->exists($oldKey)) {
            throw new WSException("File '$oldName' does not exist for exam {$this->id}", 404);
        }

        if ($oldName === $newName) {
            return $this->list_files();
        }

        $newKey = $this->unassignedFileKey($newName);
        if ($store->exists($newKey)) {
            throw new WSException("File '$newName' already exists", 409);
        }

        $store->copy($oldKey, $newKey);
        $store->delete($oldKey);

        $tags = $this->loadFileTags();
        if (isset($tags[$oldName])) {
            $tags[$newName] = $tags[$oldName];
            unset($tags[$oldName]);
            $this->saveFileTags($tags);
        }

        return $this->list_files();
    }

    /**
     * Drop tags for a file that is being deleted.
     */
    public function removeFileTags(string $filename): void
    {
        $tags = $this->loadFileTags();
        if (!isset($tags[$filename])) {
            return;
        }
        unset($tags[$filename]);
        $this->saveFileTags($tags);
    }

    /**
     * @return array<string, array{type: string, student: string}>
     */
    public function loadFileTags(): array
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            return [];
        }

        $store = ObjectStore::getInstance();
        $key = ObjectStore::examFilesCsvKey($this->school_id, $this->user_id, $this->id);
        if (!$store->exists($key)) {
            return [];
        }

        $rows = CsvStore::decodeRows($store->getContents($key));
        $tags = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $tags[$name] = [
                'type' => $row['type'] ?? '',
                'student' => $row['student'] ?? $row['author'] ?? '',
            ];
        }
        return $tags;
    }

    /**
     * @param array<string, array{type?: string, student?: string}> $tags
     */
    public function saveFileTags(array $tags): void
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            throw new Exception('Cannot save file tags without id, school_id and user_id');
        }

        $rows = [];
        foreach ($tags as $name => $tag) {
            $type = $tag['type'] ?? '';
            $student = $tag['student'] ?? '';
            if ($type === '' && $student === '') {
                continue;
            }
            $rows[] = [
                'name' => (string) $name,
                'type' => $type,
                'student' => $student,
            ];
        }

        $store = ObjectStore::getInstance();
        $key = ObjectStore::examFilesCsvKey($this->school_id, $this->user_id, $this->id);
        if ($rows === []) {
            if ($store->exists($key)) {
                $store->delete($key);
            }
            return;
        }

        $store->putContents($key, CsvStore::encodeRows($rows), 'text/csv');
    }

    /**
     * Answer exam questions from the attached unassigned files via the LLM client.
     * Questions are passed by the caller (not persisted on the exam CSV).
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
            foreach ($this->list_files() as $file) {
                $key = $this->unassignedFileKey($file['name']);
                $tmpPath = $store->downloadToTemp($key);
                $tempFiles[] = $tmpPath;
                $request->add_file($tmpPath, $file['name']);
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
     * Grade a submission via OpenRouter: annotated image + textual mark/appreciation.
     *
     * @return array Updated file list
     */
    public function correctSubmission(string $filename, string $language): array
    {
        $tags = $this->loadFileTags();
        $type = $tags[$filename]['type'] ?? '';
        if ($type !== 'submission') {
            throw new WSException('File is not a submission', 400);
        }

        $store = ObjectStore::getInstance();
        $key = $this->unassignedFileKey($filename);
        if (!$store->exists($key)) {
            throw new WSException("File '$filename' does not exist for exam {$this->id}", 404);
        }

        $student = $tags[$filename]['student'] ?? '';
        $instructionText = $this->instructionFilesText();
        $languageName = trim($language) !== '' ? trim($language) : 'French';

        $prompt = 'SYSTEM: You are a professor in ' . $this->subject
            . ' and you have to correct the following submission. '
            . 'Respond by annotating the image plus a textual response with the mark and the appreciation. '
            . 'Use the language ' . $languageName
            . ' with the following instructions bellow. '
            . $instructionText;

        $imageModel = $_ENV['OPENROUTER_IMAGE_MODEL'] ?? 'google/gemini-2.5-flash-image';
        $request = LlmClientFactory::create($imageModel);
        $request->set_system_content($prompt);
        $request->add_text($prompt);
        $request->enable_image_output();

        $tmpPath = $store->downloadToTemp($key);
        try {
            $request->add_file($tmpPath, $filename);
            $result = $request->call_annotation();
        } catch (\Throwable $th) {
            throw new WSException($th->getMessage(), 400);
        } finally {
            @unlink($tmpPath);
        }

        if ($result['images'] === []) {
            throw new WSException('The model did not return an annotated image', 400);
        }

        $image = $result['images'][0];
        $imageExt = self::extensionForMime($image['mime']);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $this->createFile(
            $base . '_correction.' . $imageExt,
            $image['body'],
            $image['mime'],
            'correction',
            $student
        );
        $this->createFile(
            $base . '_correction.txt',
            $result['text'] !== '' ? $result['text'] : "\n",
            'text/plain; charset=utf-8',
            'correction',
            $student
        );

        return $this->list_files();
    }

    private function instructionFilesText(): string
    {
        $store = ObjectStore::getInstance();
        $parts = [];
        foreach ($this->list_files() as $file) {
            if (($file['type'] ?? '') !== 'instructions') {
                continue;
            }
            $parts[] = $file['name'] . ":\n" . $store->getContents($this->unassignedFileKey($file['name']));
        }
        return implode("\n\n", $parts);
    }

    private static function extensionForMime(string $mime): string
    {
        $map = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $baseMime = strtolower(trim(explode(';', $mime)[0]));
        return $map[$baseMime] ?? 'png';
    }
}
