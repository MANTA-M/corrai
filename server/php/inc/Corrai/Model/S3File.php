<?php

namespace Corrai\Model;

use Exception;
use Corrai\Utils\MenuLabels;
use Corrai\Utils\Store\ObjectStore;

/**
 * One S3 object.
 *
 * Used as the bytes of an InputFile (content, attributes.json, event JSON)
 * and as a bare assessment file (solution, correction, debug, or a file that
 * has no role yet). A bare file has no directory, no attributes.json and no events.
 */
class S3File
{
    public string $key = '';

    public ?string $id = null;

    public string $school_id = '';

    public string $user_id = '';

    public string $assessment_id = '';

    public string $name = '';

    public string $type = '';

    public ?string $student = null;

    public string $status = '';

    public string $content_type = 'application/octet-stream';

    public int $size = 0;

    public int $created = 0;

    public ?string $etag = null;

    /**
     * True when this role is stored as a single object under blobs/.
     */
    public static function storesAsBlob(string $type): bool
    {
        return !in_array($type, ['submission', 'subject', 'instructions'], true);
    }

    public static function at(string $key): self
    {
        $file = new self();
        $file->key = $key;
        return $file;
    }

    public function getContents(): string
    {
        return ObjectStore::getInstance()->getContents($this->key);
    }

    public function putContents(string $body, ?string $contentType = null): ?string
    {
        if ($contentType !== null && $contentType !== '') {
            $this->content_type = $contentType;
        }
        $this->size = strlen($body);
        $metadata = ($this->id !== null && $this->id !== '') ? $this->userMetadata() : null;
        $this->etag = ObjectStore::getInstance()->putContents(
            $this->key,
            $body,
            $this->content_type,
            null,
            $metadata
        );
        return $this->etag;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function putJson(array $data): ?string
    {
        if (!isset($data['schema'])) {
            $data['schema'] = ObjectStore::SCHEMA;
        }
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new Exception('Failed to encode JSON for ' . $this->key);
        }
        return $this->putContents($body, 'application/json');
    }

    public function contentKey(): string
    {
        return $this->key;
    }

    /**
     * Bare files have no sibling objects. The trailing slash keeps annex lookups off the blob key.
     */
    public function prefix(): string
    {
        return $this->key . '/';
    }

    /**
     * @return array<string, string>
     */
    public function userMetadata(): array
    {
        $meta = [
            'name' => rawurlencode($this->name),
            'type' => $this->type,
            'created' => (string) $this->created,
        ];
        if ($this->student !== null && $this->student !== '') {
            $meta['student'] = $this->student;
        }
        return $meta;
    }

    public static function from_hash(string $hash): self
    {
        $store = ObjectStore::getInstance();
        $pointer = $store->resolveIdPointer($hash);
        $parsed = ObjectStore::parseNodePrefix($pointer);
        if (($parsed['kind'] ?? '') !== 'blob') {
            throw new Exception("Hash $hash does not point to a blob");
        }

        $key = rtrim($pointer, '/');
        $head = $store->head($key);
        $meta = self::normalizeMetadata($head['Metadata'] ?? []);

        $file = new self();
        $file->key = $key;
        $file->id = $parsed['file_id'] ?? null;
        $file->school_id = (string) ($parsed['school_id'] ?? '');
        $file->user_id = (string) ($parsed['teacher_id'] ?? '');
        $file->assessment_id = (string) ($parsed['assessment_id'] ?? '');
        $file->etag = $head['ETag'] ?? null;
        $file->size = (int) ($head['ContentLength'] ?? 0);
        $file->content_type = (string) ($head['ContentType'] ?? 'application/octet-stream');
        $file->name = rawurldecode((string) ($meta['name'] ?? ''));
        $file->type = (string) ($meta['type'] ?? '');
        $student = $meta['student'] ?? null;
        $file->student = is_string($student) && $student !== '' ? $student : null;
        $file->created = (int) ($meta['created'] ?? 0);
        $file->status = '';
        return $file;
    }

    /**
     * Rewrite name, type, student and content type. The object body stays.
     */
    public function saveAttributes(bool $retry = true): void
    {
        if ($this->id === null || $this->id === '' || $this->key === '') {
            throw new Exception('Cannot save blob attributes without id');
        }
        ObjectStore::getInstance()->replaceMetadata($this->key, $this->userMetadata(), $this->content_type);
        ObjectStore::getInstance()->setIdPointer($this->id, $this->key, false);
    }

    /**
     * @return string[]
     */
    public function listAnnexes(): array
    {
        return [];
    }

    /**
     * @return array<int, array{id: string, timestamp: int, name: string}>
     */
    public function listEvents(): array
    {
        return [];
    }

    public function delete(): void
    {
        if ($this->id === null || $this->id === '') {
            throw new Exception('Cannot delete blob without id');
        }
        $store = ObjectStore::getInstance();
        if ($this->key !== '' && $store->exists($this->key)) {
            $store->delete($this->key);
        }
        $store->deleteIdPointer($this->id);
    }

    public function localizedLabel(string $locale): string
    {
        $type = $this->type === '' ? 'unknown' : $this->type;
        return MenuLabels::text('file_type_' . $type, $locale);
    }

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
        $items[] = MenuLabels::item('rename', $locale, 'pencil', MenuLabels::BLUE);
        $items[] = MenuLabels::item('delete', $locale, 'trash', MenuLabels::DANGER, 'file_delete');
        return $items;
    }

    public function get_status_label(?string $locale = null): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
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
            'status_label' => '',
            'content_type' => $this->content_type,
            'label' => $this->localizedLabel($locale),
            'menu' => $this->get_menu($locale),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, string>
     */
    private static function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[strtolower($key)] = is_string($value) ? $value : (string) $value;
        }
        return $normalized;
    }
}
