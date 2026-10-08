<?php

namespace Corrai\Queue;

use InvalidArgumentException;
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
     * Enqueue a ticket for a file with a given task.
     */
    public function enqueueFile(string $fileId, string $task): void
    {
        if ($task === null) {
            throw new InvalidArgumentException('Task is required');
        }
        $payload = json_encode(['file_id' => $fileId, 'task' => $task], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RedisException('Failed to encode file queue ticket');
        }
        $this->client->lPush(self::LIST_KEY, $payload);
        error_log('Enqueued file ticket ' . $fileId . ' on ' . self::LIST_KEY);
    }

    /**
     * Enqueue a ticket for an assessment with a given task.
     */
    public function enqueueAssessment(string $assessmentId, string $task): void
    {
        if ($task === null || $task === '') {
            throw new InvalidArgumentException('Task is required');
        }
        $payload = json_encode(['assessment_id' => $assessmentId, 'task' => $task], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RedisException('Failed to encode assessment queue ticket');
        }
        $this->client->lPush(self::LIST_KEY, $payload);
        error_log('Enqueued assessment ticket ' . $assessmentId . ' on ' . self::LIST_KEY);
    }

    /**
     * Enqueue a ticket for a student with a given task.
     */
    public function enqueueStudent(string $studentId, string $task): void
    {
        if ($task === null || $task === '') {
            throw new InvalidArgumentException('Task is required');
        }
        $payload = json_encode(['student_id' => $studentId, 'task' => $task], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RedisException('Failed to encode student queue ticket');
        }
        $this->client->lPush(self::LIST_KEY, $payload);
        error_log('Enqueued student ticket ' . $studentId . ' on ' . self::LIST_KEY);
    }

    /**
     * Enqueue a content path for the Python OCR consumer.
     *
     * ``$operation`` is stored but ignored. When OCR finishes, the Python
     * consumer enqueues ``$after_task`` on this same path in the PHP queue.
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
     * A ticket is either a file-status job (``file_id``) or a path task
     * (``path`` plus ``task``) enqueued after OCR.
     *
     * @return array{file_id?: string, path?: string, task?: string}|null
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
        if (!is_array($decoded)) {
            error_log('Invalid Redis file queue ticket: ' . $raw);
            return null;
        }

        $ticket = [];
        if (isset($decoded['file_id']) && is_string($decoded['file_id']) && $decoded['file_id'] !== '') {
            $ticket['file_id'] = $decoded['file_id'];
        }
        if (isset($decoded['assessment_id']) && is_string($decoded['assessment_id']) && $decoded['assessment_id'] !== '') {
            $ticket['assessment_id'] = $decoded['assessment_id'];
        }
        if (isset($decoded['student_id']) && is_string($decoded['student_id']) && $decoded['student_id'] !== '') {
            $ticket['student_id'] = $decoded['student_id'];
        }
        if (isset($decoded['path']) && is_string($decoded['path']) && $decoded['path'] !== '') {
            $ticket['path'] = $decoded['path'];
        }
        if (isset($decoded['task']) && is_string($decoded['task']) && $decoded['task'] !== '') {
            $ticket['task'] = $decoded['task'];
        }

        $hasFile = isset($ticket['file_id']);
        $hasAssessment = isset($ticket['assessment_id']);
        $hasStudent = isset($ticket['student_id']);
        $hasPathTask = isset($ticket['path'], $ticket['task']);
        if (!$hasFile && !$hasAssessment && !$hasStudent && !$hasPathTask) {
            error_log('Invalid Redis file queue ticket: ' . $raw);
            return null;
        }

        return $ticket;
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
