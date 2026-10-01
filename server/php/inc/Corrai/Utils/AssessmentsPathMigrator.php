<?php

namespace Corrai\Utils;

/**
 * Move SeaweedFS keys from teachers/.../exams/ to teachers/.../assessments/.
 *
 * Idempotent: objects already copied are left in place, and the old key is
 * removed only after the destination matches. _id pointers are rewritten.
 */
class AssessmentsPathMigrator
{
    public const OLD_SEGMENT = '/exams/';
    public const NEW_SEGMENT = '/assessments/';

    private ObjectStore $store;

    /** @var list<string> */
    private array $messages = [];

    public function __construct(?ObjectStore $store = null)
    {
        $this->store = $store ?? ObjectStore::getInstance();
    }

    public static function rewriteKey(string $key): string
    {
        return str_replace(self::OLD_SEGMENT, self::NEW_SEGMENT, $key);
    }

    /**
     * Rewrite stored JSON: exam_id keys and embedded /exams/ paths.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function rewriteJson(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $newKey = $key === 'exam_id' ? 'assessment_id' : $key;
            if (is_string($value)) {
                $value = self::rewriteKey($value);
            } elseif (is_array($value)) {
                $value = self::rewriteJson($value);
            }
            $out[$newKey] = $value;
        }
        return $out;
    }

    /**
     * @return list<string> Log lines
     */
    public function run(): array
    {
        $this->messages = [];
        $this->store->ensureBucket();

        $sources = [];
        foreach ($this->store->listKeys('schools/') as $key) {
            if (str_contains($key, self::OLD_SEGMENT)) {
                $sources[] = $key;
            }
        }

        $readyToDelete = [];
        foreach ($sources as $key) {
            if ($this->copyObject($key)) {
                $readyToDelete[] = $key;
            }
        }

        $this->rewriteIdPointers();

        foreach ($readyToDelete as $key) {
            $this->store->delete($key);
            $this->log("  deleted $key");
        }

        $this->log(count($sources) === 0
            ? 'No exams/ keys to migrate.'
            : 'Migrated ' . count($readyToDelete) . ' of ' . count($sources) . ' object(s).');

        return $this->messages;
    }

    /**
     * Copy one exams/ object onto its assessments/ key.
     *
     * @return bool True when the destination is in sync and the source may be deleted
     */
    private function copyObject(string $key): bool
    {
        $dest = self::rewriteKey($key);
        $body = $this->objectBody($key);
        if ($body === null) {
            $this->log("  skip unreadable $key");
            return false;
        }

        if ($this->store->exists($dest)) {
            $existing = $this->store->getContents($dest);
            if ($existing !== $body) {
                $this->log("  conflict $dest already differs from $key");
                return false;
            }
            $this->log("  already migrated $dest");
            return true;
        }

        $contentType = str_ends_with($key, '.json') ? 'application/json' : null;
        if ($contentType === null) {
            $this->store->copy($key, $dest);
        } else {
            $this->store->putContents($dest, $body, $contentType);
        }
        $this->log("  copied $key -> $dest");
        return true;
    }

    /**
     * Destination body: JSON objects are rewritten, other objects are copied as-is.
     */
    private function objectBody(string $key): ?string
    {
        $raw = $this->store->getContents($key);
        if (!str_ends_with($key, '.json')) {
            return $raw;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $raw;
        }

        $encoded = json_encode(self::rewriteJson($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? null : $encoded;
    }

    private function rewriteIdPointers(): void
    {
        foreach ($this->store->listKeys('_id/') as $key) {
            $body = $this->store->getContents($key);
            if (!str_contains($body, self::OLD_SEGMENT)) {
                continue;
            }
            $updated = self::rewriteKey($body);
            $this->store->putContents($key, $updated, 'text/plain');
            $this->log("  pointer $key -> $updated");
        }
    }

    private function log(string $message): void
    {
        $this->messages[] = $message;
    }
}
