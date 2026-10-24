<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Queue\RedisQueue;
use Corrai\Subject\LawFrance\Assessment;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use Redis;

class LawFranceCorrectionGridTest extends TestCase
{
    /** @var list<array{key: string, payload: string}> */
    private array $queued = [];

    protected function tearDown(): void
    {
        ObjectStore::resetInstance();
        RedisQueue::setInstance(null);
        parent::tearDown();
    }

    public function testCreateCorrectionGridEnqueuesThePythonTask(): void
    {
        $this->installQueue();

        $assessment = new Assessment();
        $assessment->id = 'ass-1';
        $assessment->create_correction_grid();

        $this->assertCount(1, $this->queued);
        $this->assertSame(RedisQueue::OCR_LIST_KEY, $this->queued[0]['key']);
        $ticket = json_decode($this->queued[0]['payload'], true);
        $this->assertSame('ass-1', $ticket['assessment_id'] ?? null);
        $this->assertSame('create_correction_grid', $ticket['task'] ?? null);
    }

    private function installQueue(): void
    {
        $this->queued = [];
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'brPop', 'connect'])
            ->getMock();
        $redis->method('lPush')->willReturnCallback(function (string $key, string $payload): int {
            $this->queued[] = ['key' => $key, 'payload' => $payload];
            return 1;
        });
        RedisQueue::setInstance(new RedisQueue($redis));
    }
}
