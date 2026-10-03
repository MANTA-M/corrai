<?php

namespace Corrai\Utils;

use Throwable;

/**
 * Rename the file status "loaded" to "stored", and the event "Loaded" to "Stored".
 *
 * Idempotent. Attribute documents and event documents are updated in place.
 */
class FileStoredMigrator
{
    private ObjectStore $store;

    /** @var list<string> */
    private array $messages = [];

    public function __construct(?ObjectStore $store = null)
    {
        $this->store = $store ?? ObjectStore::getInstance();
    }

    public static function isEventKey(string $key): bool
    {
        return str_contains($key, '/events/') && str_ends_with($key, '.json');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function attributeStatus(array $data): ?array
    {
        if (($data['status'] ?? null) !== 'loaded') {
            return null;
        }
        $data['status'] = 'stored';
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function eventName(array $data): ?array
    {
        if (($data['name'] ?? null) !== 'Loaded') {
            return null;
        }
        $data['name'] = 'Stored';
        return $data;
    }

    /**
     * @return list<string> Log lines
     */
    public function run(): array
    {
        $this->messages = [];
        $this->store->ensureBucket();

        $statusUpdates = 0;
        $eventUpdates = 0;
        foreach ($this->store->listKeys('schools/') as $key) {
            if (ClassAttributeMigrator::isFileAttributeKey($key)) {
                if ($this->migrateAttribute($key)) {
                    $statusUpdates++;
                }
                continue;
            }
            if (self::isEventKey($key) && $this->migrateEvent($key)) {
                $eventUpdates++;
            }
        }

        $this->log($statusUpdates === 0 && $eventUpdates === 0
            ? 'No loaded file statuses or events to migrate.'
            : 'Stored files: ' . $statusUpdates . ' status update(s), '
                . $eventUpdates . ' event update(s).');

        return $this->messages;
    }

    private function migrateAttribute(string $key): bool
    {
        try {
            $loaded = $this->store->getJson($key);
        } catch (Throwable $e) {
            $this->log("  skip unreadable $key");
            return false;
        }

        $data = $loaded['data'];
        if (!is_array($data)) {
            $this->log("  skip unreadable $key");
            return false;
        }
        $updated = self::attributeStatus($data);
        if ($updated === null) {
            return false;
        }
        return $this->write($key, $updated, $loaded['etag'], 'status');
    }

    private function migrateEvent(string $key): bool
    {
        try {
            $loaded = $this->store->getJson($key);
        } catch (Throwable $e) {
            $this->log("  skip unreadable $key");
            return false;
        }

        $data = $loaded['data'];
        if (!is_array($data)) {
            $this->log("  skip unreadable $key");
            return false;
        }
        $updated = self::eventName($data);
        if ($updated === null) {
            return false;
        }
        return $this->write($key, $updated, $loaded['etag'], 'event');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $key, array $data, ?string $etag, string $kind): bool
    {
        try {
            $this->store->putJson($key, $data, $etag);
        } catch (StoreConflictException $e) {
            $this->log("  conflict $key");
            return false;
        }
        $this->log('  ' . $kind . ' ' . $key);
        return true;
    }

    private function log(string $message): void
    {
        $this->messages[] = $message;
    }
}
