<?php

namespace Corrai\Model;

use Exception;
use Corrai\Utils\HashId;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\StoreConflictException;
use Corrai\Utils\WSException;

/**
 * Shared student model. Subject packages may provide a concrete Student.
 */
class BaseStudent
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

    public ?float $mark = null;

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
        $student = static::from_array($loaded['data']);
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

    /**
     * Persist with optional If-Match. Retries a few times on conflict when $retry is true.
     */
    public function save(bool $retry = true): void
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
        ];

        $attempts = $retry ? 5 : 1;
        $store = ObjectStore::getInstance();
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $this->etag = $store->putJson($this->attrKey(), $payload, $this->etag);
                $store->setIdPointer($this->id, $this->prefix());
                return;
            } catch (StoreConflictException $e) {
                if ($i === $attempts - 1) {
                    throw $e;
                }
                $loaded = $store->getJson($this->attrKey());
                $this->etag = $loaded['etag'];
            }
        }
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

    public function to_output(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'mark' => $this->mark,
        ];
    }
}
