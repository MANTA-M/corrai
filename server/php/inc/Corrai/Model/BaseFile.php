<?php

namespace Corrai\Model;

use Exception;
use Corrai\Utils\HashId;
use Corrai\Utils\MenuLabels;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\StoreConflictException;
use Corrai\Utils\WSException;

/**
 * Shared assessment file model. Subject packages may provide a concrete File.
 */
abstract class BaseFile
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

    /**
     * True when the attribute document named this instance's class.
     */
    public bool $hasStoredClass = false;

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
        $status = (string) ($data['status'] ?? '');
        $file->status = $status === 'loaded' ? 'stored' : $status;
        $file->content_type = (string) ($data['content_type'] ?? 'application/octet-stream');
        $storedClass = $data['class'] ?? null;
        $file->hasStoredClass = is_string($storedClass) && $storedClass === static::class;
        return $file;
    }

    /**
     * Concrete file class named by an attribute document.
     *
     * @return class-string<BaseFile>|null
     */
    public static function classFromPayload(array $data): ?string
    {
        $class = $data['class'] ?? null;
        if (!is_string($class) || !self::isFileClass($class)) {
            return null;
        }
        return $class;
    }

    /**
     * True when $class can be constructed as a file.
     */
    public static function isFileClass(string $class): bool
    {
        if (!str_starts_with($class, 'Corrai\\') || str_contains($class, '@')) {
            return false;
        }
        if (!class_exists($class)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);
        return !$reflection->isAbstract()
            && ($class === File::class || $reflection->isSubclassOf(File::class));
    }

    /**
     * Class written to S3 for this file.
     *
     * @return class-string<File>
     */
    public function storedClass(): string
    {
        $class = static::class;
        if (self::isFileClass($class)) {
            return $class;
        }
        return File::class;
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
            'type' => $this->type,
            'student' => $this->student,
            'status' => $this->status,
            'content_type' => $this->content_type,
            'size' => $this->size,
            'created' => $this->created,
            'class' => $this->storedClass(),
        ];
    }

    /**
     * Copy of this file as $class, keeping the stored fields and etag.
     */
    public function asClass(string $class): self
    {
        if (!self::isFileClass($class)) {
            throw new Exception('Invalid file class ' . $class);
        }
        $copy = $class::from_array($this->attributeState());
        $copy->etag = $this->etag;
        $copy->hasStoredClass = false;
        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributeState(): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'user_id' => $this->user_id,
            'assessment_id' => $this->assessment_id,
            'name' => $this->name,
            'type' => $this->type,
            'student' => $this->student,
            'status' => $this->status,
            'content_type' => $this->content_type,
            'size' => $this->size,
            'created' => $this->created,
        ];
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
        $class = self::resolveFileClass($loaded['data'], $parsed);
        if (!is_a($class, static::class, true)) {
            $class = static::class;
        }
        $file = $class::from_array($loaded['data']);
        $file->id = $parsed['file_id'];
        $file->school_id = $parsed['school_id'];
        $file->user_id = $parsed['teacher_id'];
        $file->assessment_id = $parsed['assessment_id'];
        $file->etag = $loaded['etag'];
        return $file;
    }

    /**
     * Stored class, or the parent assessment's file class when the attribute is absent.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $parsed
     * @return class-string<File>
     */
    private static function resolveFileClass(array $data, array $parsed): string
    {
        $stored = self::classFromPayload($data);
        if ($stored !== null) {
            return $stored;
        }
        $assessmentId = $parsed['assessment_id'] ?? '';
        if (is_string($assessmentId) && $assessmentId !== '') {
            try {
                $class = BaseAssessment::from_hash($assessmentId)->fileClass();
                if (self::isFileClass($class)) {
                    return $class;
                }
            } catch (\Throwable $e) {
                // The generic file remains readable when the assessment cannot be loaded.
            }
        }
        return File::class;
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

    public function markupAnnotationsKey(): string
    {
        return ObjectStore::assessmentFileMarkupAnnotationsKey(
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

        $payload = $this->attributePayload();

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

    /**
     * Base status labels. Subject files add their own statuses by overriding get_status_label().
     *
     * @var array<string, array<string, string>>
     */
    protected const STATUS_LABELS = [
        'stored' => [
            'en' => 'Stored',
            'fr' => 'Stocké',
            'ru' => 'Сохранено',
            'uk' => 'Збережено',
            'es' => 'Almacenado',
            'pt' => 'Armazenado',
            'ro' => 'Stocat',
            'de' => 'Gespeichert',
        ],
    ];

    /**
     * Localized name of this file's type.
     */
    public function localizedLabel(string $locale): string
    {
        $type = $this->type === '' ? 'unknown' : $this->type;
        return MenuLabels::text('file_type_' . $type, $locale);
    }

    /**
     * Localized label of the current status. An unknown status is returned unchanged.
     */
    public function get_status_label(?string $locale = null): string
    {
        $status = $this->status === 'loaded' ? 'stored' : $this->status;
        $labels = self::STATUS_LABELS[$status] ?? null;
        if (!is_array($labels)) {
            return $status;
        }
        return MenuLabels::pick($labels, MenuLabels::locale($locale), $status);
    }

    /**
     * Actions the client can offer for this file.
     *
     * @return array<int, array{key: string, label: string, icon: string, color: string}>
     */
    public function get_menu(string $locale): array
    {
        $items = [
            MenuLabels::item('view', $locale, 'eye', MenuLabels::BLUE),
        ];
        if ($this->canReassign()) {
            $items[] = MenuLabels::item('reassign', $locale, 'person', MenuLabels::BLUE);
        }
        $items[] = MenuLabels::item('events', $locale, 'list', MenuLabels::MUTED);
        $items[] = MenuLabels::item('rename', $locale, 'pencil', MenuLabels::BLUE);
        $items[] = MenuLabels::item('delete', $locale, 'trash', MenuLabels::DANGER, 'file_delete');
        return $items;
    }

    public function to_output(?string $studentName = null, ?string $locale = null): array
    {
        $locale = MenuLabels::locale($locale);
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'created' => $this->created,
            'type' => $this->type,
            'student' => $this->student,
            'student_name' => $studentName,
            'status' => $this->status,
            'status_label' => $this->get_status_label($locale),
            'content_type' => $this->content_type,
            'label' => $this->localizedLabel($locale),
            'menu' => $this->get_menu($locale),
        ];
    }

    /**
     * Subject material stays with the assessment. Copies can move between students.
     */
    public function canReassign(): bool
    {
        if (in_array($this->type, ['subject', 'solution', 'instructions'], true)) {
            return false;
        }
        if ($this->type === 'submission' || $this->type === '') {
            return true;
        }
        return trim((string) $this->student) === '';
    }

    /**
     * Called when the file is stored.
     */
    abstract public function on_stored(): void;
}
