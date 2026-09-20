<?php

namespace Corrai;

use Exception;
use Corrai\LlmClient\Gemini3Client;

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
     * List all files in this exam's unassigned/ folder.
     *
     * @return array Array of file information, each containing 'name', 'size', and 'created' keys
     */
    public function list_files(): array
    {
        if (empty($this->id) || $this->school_id === '' || $this->user_id === '') {
            return [];
        }

        return ObjectStore::getInstance()->list(
            ObjectStore::examUnassignedPrefix($this->school_id, $this->user_id, $this->id)
        );
    }

    /**
     * Answer exam questions from the attached unassigned files via the LLM client.
     * Questions are passed by the caller (not persisted on the exam CSV).
     *
     * @param array $questions List of question arrays with a 'text' key, or plain strings
     */
    public function verify(array $questions = []): array
    {
        $request = new Gemini3Client();
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
}
