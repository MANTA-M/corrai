<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Queue\RedisQueue;
use Corrai\Subject\DictationFranceCM2\Assessment;
use Corrai\Utils\Http\WSException;
use PHPUnit\Framework\TestCase;
use Redis;

class GoogleVisionOcrUnclassifiedTest extends TestCase
{
    /** @var list<array{key: string, payload: string}> */
    private array $queued = [];

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        parent::tearDown();
    }

    public function testEnqueuesThePythonTask(): void
    {
        $this->installQueue();

        $assessment = new Assessment();
        $assessment->id = 'ass-1';
        $assessment->google_vision_ocr_unclassified();

        $this->assertCount(1, $this->queued);
        $this->assertSame(RedisQueue::OCR_LIST_KEY, $this->queued[0]['key']);
        $ticket = json_decode($this->queued[0]['payload'], true);
        $this->assertSame('ass-1', $ticket['assessment_id'] ?? null);
        $this->assertSame('google_vision_ocr_unclassified', $ticket['task'] ?? null);
    }

    public function testRequiresAssessmentId(): void
    {
        $assessment = new Assessment();
        $this->expectException(WSException::class);
        $assessment->google_vision_ocr_unclassified();
    }

    public function testMenuIncludesGoogleVisionOcr(): void
    {
        $assessment = new Assessment();
        $keys = array_column($assessment->get_menu('en'), 'key');
        $this->assertContains('google_vision_ocr_unclassified', $keys);
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
