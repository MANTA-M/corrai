<?php

namespace Corrai\Queue;

use Redis;
use RedisException;

/**
 * Redis list used as a file-status work queue.
 */
class RedisQueue
{
    public const LIST_KEY = 'corrai:files';
    public const OCR_LIST_KEY = 'corrai:ocr';

    private static ?self $instance = null;

    private Redis $client;

    public function __construct(?Redis $client = null)
    {
        if ($client !== null) {
            $this->client = $client;
            return;
        }

        $host = (string) ($_ENV['REDIS_HOST'] ?? '127.0.0.1');
        $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);

        $redis = new Redis();
        $redis->connect($host, $port);
        $this->client = $redis;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Enqueue a ticket for a file that was just stored.
     */
    public function enqueueFile(string $fileId): void
    {
        $payload = json_encode(['file_id' => $fileId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RedisException('Failed to encode file queue ticket');
        }
        $this->client->lPush(self::LIST_KEY, $payload);
        error_log('Enqueued file ticket ' . $fileId . ' on ' . self::LIST_KEY);
    }

    /**
     * Enqueue a content path for the Python OCR consumer.
     */
    public function enqueueOcr(string $contentPath, string $operation, string $after_task = '', string $lang = 'fr'): void
    {
        $payload = json_encode(
            ['path' => $contentPath, 'operation' => $operation, 'after_task' => $after_task, 'lang' => $lang],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            throw new RedisException('Failed to encode OCR queue ticket');
        }
        $this->client->lPush(self::OCR_LIST_KEY, $payload);
        error_log('Enqueued OCR ticket path=' . $contentPath . ' lang=' . $lang . ' on ' . self::OCR_LIST_KEY);
    }

    /**
     * Blocking pop of the next ticket. Returns null on timeout.
     *
     * @return array{file_id: string}|null
     */
    public function blockingPop(int $timeoutSeconds = 5): ?array
    {
        $result = $this->client->brPop([self::LIST_KEY], $timeoutSeconds);
        if ($result === false || $result === null) {
            return null;
        }

        // brPop returns [key, value]
        $raw = is_array($result) ? ($result[1] ?? null) : null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['file_id']) || !is_string($decoded['file_id'])) {
            error_log('Invalid Redis file queue ticket: ' . $raw);
            return null;
        }

        return ['file_id' => $decoded['file_id']];
    }

    /**
     * Peek raw tickets without consuming them.
     *
     * Order matches consumer pop order (list tail first): LPUSH + BRPOP.
     *
     * @return list<string>
     */
    public function listTickets(string $key): array
    {
        $raw = $this->client->lRange($key, 0, -1);
        if ($raw === false || !is_array($raw)) {
            return [];
        }

        $tickets = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $tickets[] = $item;
            }
        }

        return array_reverse($tickets);
    }
}
