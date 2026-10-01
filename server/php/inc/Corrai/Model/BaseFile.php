<?php

namespace Corrai\Model;

use Exception;
use Corrai\Utils\HashId;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\StoreConflictException;
use Corrai\Utils\WSException;

/**
 * Shared assessment file model. Subject packages may provide a concrete File.
 */
class BaseFile
{
    public ?string $id = null;
    public string $school_id = '';
    public string $user_id = '';
    public string $assessment_id = '';

    public string $name = '';

    public int $size = 0;

    /**
     * Unix timestamp of the stored object.
     */
    public int $created = 0;

    /**
     * One of BaseAssessment::FILE_TYPES, or an empty string when unset.
     */
    public string $type = '';

    /**
     * Student hash this file belongs to. Null / empty when unassigned.
     */
    public ?string $student = null;

    public string $status = '';

    public string $content_type = 'application/octet-stream';

    /** @var string|null Last known ETag for conditional attribute updates */
    public ?string $etag = null;

    public static function from_array(array $data): static
    {
        $file = new static();
        $file->id = $data['id'] ?? null;
        $file->school_id = $data['school_id'] ?? '';
        $file->user_id = $data['user_id'] ?? '';
        $file->assessment_id = $data['assessment_id'] ?? '';
        $file->name = (string) ($data['name'] ?? '');
        $file->size = (int) ($data['size'] ?? 0);
        $file->created = (int) ($data['created'] ?? 0);
        $file->type = (string) ($data['type'] ?? '');
        $student = $data['student'] ?? null;
        if (is_string($student) && trim($student) !== '') {
            $file->student = trim($student);
        } else {
            $file->student = null;
        }
        $file->status = (string) ($data['status'] ?? '');
        $file->content_type = (string) ($data['content_type'] ?? 'application/octet-stream');
        return $file;
    }

    public function validate(): void
    {
        if (trim($this->school_id) === '' || trim($this->user_id) === '' || trim($this->assessment_id) === '') {
            throw new WSException('File school_id, user_id and assessment_id are required', 400);
        }
        if (trim($this->name) === '' || preg_match('/[\/\\\\]/', $this->name)) {
            throw new WSException('Invalid file name', 400);
        }
        if ($this->type !== '' && !in_array($this->type, BaseAssessment::FILE_TYPES, true)) {
            throw new WSException('Invalid file type', 400);
        }
    }

    public static function from_hash(string $hash): static
    {
        $store = ObjectStore::getInstance();
        $prefix = $store->resolveIdPointer($hash);
        $parsed = ObjectStore::parseNodePrefix($prefix);
        if ($parsed['kind'] !== 'file') {
            throw new Exception("Hash $hash does not point to a file");
        }

        $attrKey = rtrim($prefix, '/') . '/' . ObjectStore::ATTR_FILE;
        if (!$store->exists($attrKey)) {
            throw new Exception("File with hash $hash does not exist");
        }

        $loaded = $store->getJson($attrKey);
        $file = static::from_array($loaded['data']);
        $file->id = $parsed['file_id'];
        $file->school_id = $parsed['school_id'];
        $file->user_id = $parsed['teacher_id'];
        $file->assessment_id = $parsed['assessment_id'];
        $file->etag = $loaded['etag'];
        return $file;
    }

    public function attrKey(): string
    {
        return ObjectStore::assessmentFileAttrKey(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            $this->id,
            $this->type,
            $this->student
        );
    }

    public function contentKey(): string
    {
        return ObjectStore::assessmentFileContentKey(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            $this->id,
            $this->type,
            $this->student
        );
    }

    public function ocrResultKey(): string
    {
        return ObjectStore::assessmentFileOcrResultKey(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            $this->id,
            $this->type,
            $this->student
        );
    }

    public function foundErrorsKey(): string
    {
        return ObjectStore::assessmentFileFoundErrorsKey(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            $this->id,
            $this->type,
            $this->student
        );
    }

    public function markupDirectivesKey(): string
    {
        return ObjectStore::assessmentFileMarkupDirectivesKey(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            $this->id,
            $this->type,
            $this->student
        );
    }

    public function prefix(): string
    {
        return ObjectStore::assessmentFilePrefix(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            $this->id,
            $this->type,
            $this->student
        );
    }

    /**
     * Persist attributes with optional If-Match. Retries on conflict when $retry is true.
     */
    public function saveAttributes(bool $retry = true): void
    {
        if ($this->id === null || $this->id === '') {
            throw new Exception('Cannot save file attributes without id');
        }
        $this->validate();

        $payload = [
            'name' => $this->name,
            'type' => $this->type,
            'student' => $this->student,
            'status' => $this->status,
            'content_type' => $this->content_type,
            'size' => $this->size,
            'created' => $this->created,
        ];

        $attempts = $retry ? 5 : 1;
        $store = ObjectStore::getInstance();
        $desired = $this->prefix();
        $previous = null;
        if ($store->exists(ObjectStore::idIndexKey($this->id))) {
            $current = $store->resolveIdPointer($this->id);
            if ($current !== $desired) {
                $store->copyPrefix($current, $desired);
                $this->etag = null;
                $previous = $current;
            }
        }
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $this->etag = $store->putJson($this->attrKey(), $payload, $this->etag);
                $store->setIdPointer($this->id, $desired);
                if ($previous !== null) {
                    $store->deletePrefix($previous);
                    $previous = null;
                }
                return;
            } catch (StoreConflictException $e) {
                if ($i === $attempts - 1) {
                    throw $e;
                }
                $loaded = $store->getJson($this->attrKey());
                $this->etag = $loaded['etag'];
                // Keep local field changes; only refresh etag for retry.
            }
        }
    }

    /**
     * Files stored next to this assessment file, excluding attributes and the main content blob.
     *
     * @return string[]
     */
    public function listAnnexes(): array
    {
        $names = ObjectStore::getInstance()->listImmediateFiles($this->prefix());
        $excluded = [ObjectStore::ATTR_FILE, ObjectStore::CONTENT_FILE];
        $annexes = array_values(array_filter(
            $names,
            static fn(string $name): bool => !in_array($name, $excluded, true)
        ));
        sort($annexes, SORT_STRING);
        return $annexes;
    }

    /**
     * Immutable events recorded for this file, oldest first.
     *
     * @return array<int, array{id: string, timestamp: int, name: string}>
     */
    public function listEvents(): array
    {
        $store = ObjectStore::getInstance();
        $prefix = ObjectStore::assessmentFileEventsPrefix(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            (string) $this->id,
            $this->type,
            $this->student
        );
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
                    $label = $eventName;
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

    /**
     * Append an immutable event object under this file.
     */
    public function appendEvent(string $name, ?int $timestamp = null): void
    {
        if ($this->id === null || $this->id === '') {
            throw new Exception('Cannot append event without file id');
        }
        $timestamp = $timestamp ?? time();
        $eventId = sprintf('%d-%s', $timestamp, bin2hex(random_bytes(4)));
        $key = ObjectStore::assessmentFileEventKey(
            $this->school_id,
            $this->user_id,
            $this->assessment_id,
            $this->id,
            $eventId,
            $this->type,
            $this->student
        );
        ObjectStore::getInstance()->putJson($key, [
            'timestamp' => $timestamp,
            'name' => $name,
        ]);
    }

    public function delete(): void
    {
        if ($this->id === null || $this->id === '') {
            throw new Exception('Cannot delete file without id');
        }
        $store = ObjectStore::getInstance();
        $store->deletePrefix($this->prefix());
        $store->deleteIdPointer($this->id);
    }

    public function to_output(?string $studentName = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'created' => $this->created,
            'type' => $this->type,
            'student' => $this->student,
            'student_name' => $studentName,
            'status' => $this->status,
            'content_type' => $this->content_type,
        ];
    }
}
