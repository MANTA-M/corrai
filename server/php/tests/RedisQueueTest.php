<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Queue\RedisQueue;
use PHPUnit\Framework\TestCase;
use Redis;

class RedisQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        parent::tearDown();
    }

    public function testListTicketsReturnsConsumerOrderAsRawStrings(): void
    {
        // LPUSH order: newest at head (index 0). BRPOP takes the tail.
        // lRange returns [newest, ..., oldest]; listTickets reverses to oldest-first.
        $raw = [
            '{"file_id":"newest"}',
            '{"file_id":"middle"}',
            '{"file_id":"oldest"}',
        ];

        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lRange', 'connect'])
            ->getMock();
        $redis->expects($this->once())
            ->method('lRange')
            ->with(RedisQueue::LIST_KEY, 0, -1)
            ->willReturn($raw);

        $queue = new RedisQueue($redis);
        $tickets = $queue->listTickets(RedisQueue::LIST_KEY);

        $this->assertSame(
            [
                '{"file_id":"oldest"}',
                '{"file_id":"middle"}',
                '{"file_id":"newest"}',
            ],
            $tickets
        );
    }

    public function testListTicketsReturnsEmptyOnFalseOrEmpty(): void
    {
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lRange', 'connect'])
            ->getMock();
        $redis->method('lRange')->willReturn(false);

        $queue = new RedisQueue($redis);
        $this->assertSame([], $queue->listTickets(RedisQueue::OCR_LIST_KEY));
    }

    public function testEnqueueOcrIncludesEngineInPayload(): void
    {
        $captured = null;
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'connect'])
            ->getMock();
        $redis->expects($this->once())
            ->method('lPush')
            ->with(
                RedisQueue::OCR_LIST_KEY,
                $this->callback(function (string $payload) use (&$captured): bool {
                    $captured = $payload;
                    return true;
                })
            )
            ->willReturn(1);

        $queue = new RedisQueue($redis);
        $queue->enqueueOcr(
            'schools/a/files/b/content',
            'ocr',
            'Corrai\\Subject\\Dictation\\Task1Correcting',
            'fr',
            'doctr'
        );

        $this->assertIsString($captured);
        $decoded = json_decode((string) $captured, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            [
                'path' => 'schools/a/files/b/content',
                'operation' => 'ocr',
                'after_task' => 'Corrai\\Subject\\Dictation\\Task1Correcting',
                'lang' => 'fr',
                'engine' => 'doctr',
            ],
            $decoded
        );
    }

    public function testEnqueueOcrDefaultsEngineToPaddle(): void
    {
        $captured = null;
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'connect'])
            ->getMock();
        $redis->expects($this->once())
            ->method('lPush')
            ->with(
                RedisQueue::OCR_LIST_KEY,
                $this->callback(function (string $payload) use (&$captured): bool {
                    $captured = $payload;
                    return true;
                })
            )
            ->willReturn(1);

        $queue = new RedisQueue($redis);
        $queue->enqueueOcr('schools/a/files/b/content', 'pre-ocr');

        $decoded = json_decode((string) $captured, true);
        $this->assertIsArray($decoded);
        $this->assertSame('paddle', $decoded['engine']);
    }

    public function testEnqueueOcrRejectsUnknownEngine(): void
    {
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'connect'])
            ->getMock();
        $redis->expects($this->never())->method('lPush');

        $queue = new RedisQueue($redis);
        $this->expectException(\InvalidArgumentException::class);
        $queue->enqueueOcr('schools/a/files/b/content', 'ocr', '', 'fr', 'tesseract');
    }
}
