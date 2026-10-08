<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Clients\Openrouter\ClaudeSonnetClient;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\LawFrance\Assessment;
use Corrai\Subject\LawFrance\AssTaskGridCreation;
use Corrai\Utils\Http\WSException;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use Redis;

class LawFranceCorrectionGridTest extends TestCase
{
    /** @var list<string> */
    private array $queued = [];

    protected function tearDown(): void
    {
        ObjectStore::resetInstance();
        RedisQueue::setInstance(null);
        parent::tearDown();
    }

    public function testCreateCorrectionGridEnqueuesTheTask(): void
    {
        $this->installQueue();

        $assessment = new Assessment();
        $assessment->id = 'ass-1';
        $assessment->create_correction_grid();

        $this->assertCount(1, $this->queued);
        $ticket = json_decode($this->queued[0], true);
        $this->assertSame('ass-1', $ticket['assessment_id'] ?? null);
        $this->assertSame(AssTaskGridCreation::class, $ticket['task'] ?? null);
        $this->assertTrue(class_exists(\LawFrance\AssTaskGridCreation::class));
    }

    public function testTaskWritesTheGridAndMovesTheStatus(): void
    {
        $compiled = '{"pages":["Cas pratique de responsabilité civile"]}';
        $grid = [
            'instructions' => ['Rédiger en français.'],
            'modificateurs_généraux' => [
                ['critère' => 'Copie illisible', 'modificateur' => -2],
            ],
            'parties' => [
                [
                    'titre' => 'Cas pratique',
                    'questions' => [
                        [
                            'titre' => 'Responsabilité',
                            'points' => 10,
                            'contenu' => 'Faits du cas.',
                            'questions_posées' => ['La responsabilité est-elle engagée ?'],
                            'critères_proposés' => [
                                ['critère' => 'Qualification exacte', 'modificateur' => 10],
                            ],
                        ],
                    ],
                    'nota_bene' => ['Citer les articles utiles.'],
                ],
            ],
        ];
        $reply = json_encode($grid, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($reply);

        $compileKey = ObjectStore::assessmentSubjectCompileKey('school', 'teacher', 'ass-1');
        $gridKey = ObjectStore::assessmentSubjectCorrectionGridKey('school', 'teacher', 'ass-1');
        $stored = null;

        $store = $this->getMockBuilder(ObjectStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exists', 'getContents', 'putContents'])
            ->getMock();
        $store->method('exists')->willReturnCallback(static fn (string $key): bool => $key === $compileKey);
        $store->method('getContents')->willReturn($compiled);
        $store->expects($this->once())
            ->method('putContents')
            ->willReturnCallback(function (string $key, string $body, ?string $contentType) use (&$stored, $gridKey): string {
                $stored = ['key' => $key, 'body' => $body, 'type' => $contentType];
                $this->assertSame($gridKey, $key);
                $this->assertSame('application/json', $contentType);
                return 'etag';
            });
        ObjectStore::setInstance($store);

        $savedStatuses = [];
        $assessment = $this->getMockBuilder(Assessment::class)
            ->onlyMethods(['save', 'instructionFilesText'])
            ->getMock();
        $assessment->id = 'ass-1';
        $assessment->school_id = 'school';
        $assessment->user_id = 'teacher';
        $assessment->method('instructionFilesText')->willReturn('Consignes de l\'épreuve');
        $assessment->method('save')->willReturnCallback(function () use ($assessment, &$savedStatuses): void {
            $savedStatuses[] = $assessment->status;
        });

        $client = new RecordingClaudeSonnetClient();
        $client->reply = $reply;

        (new AssTaskGridCreation($client))->processAssessment($assessment);

        $this->assertSame(['create_correction_grid', 'correction_grid_generated'], $savedStatuses);
        $this->assertSame('correction_grid_generated', $assessment->status);
        $this->assertSame('Grille de correction prête', $assessment->get_status_label('fr'));
        $this->assertStringContainsString('Consignes de l\'épreuve', $client->userText());
        $this->assertStringContainsString($compiled, $client->userText());
        $this->assertStringContainsString("école d'avocat", $client->systemContent());
        $this->assertStringContainsString('references', $client->systemContent());
        $this->assertStringContainsString('verbatim', $client->systemContent());
        $this->assertStringContainsString('législatif officiel', $client->systemContent());
        $this->assertStringContainsString('en vigueur', $client->systemContent());
        $this->assertStringContainsString('alinéas', $client->systemContent());
        $schema = $client->schema();
        $this->assertArrayHasKey('references', $schema['properties'] ?? []);
        $this->assertContains('references', $schema['required'] ?? []);
        $this->assertSame(['reference', 'texte'], $schema['properties']['references']['items']['required'] ?? []);
        $this->assertIsArray($stored);
        $this->assertSame($grid, json_decode($stored['body'], true));
        $this->assertStringEndsWith("\n", $stored['body']);
    }

    public function testTaskStopsBeforeTheFinalStatusWhenTheSubjectIsMissing(): void
    {
        $store = $this->getMockBuilder(ObjectStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exists', 'getContents', 'putContents'])
            ->getMock();
        $store->method('exists')->willReturn(false);
        $store->expects($this->never())->method('putContents');
        ObjectStore::setInstance($store);

        $savedStatuses = [];
        $assessment = $this->getMockBuilder(Assessment::class)
            ->onlyMethods(['save'])
            ->getMock();
        $assessment->id = 'ass-1';
        $assessment->school_id = 'school';
        $assessment->user_id = 'teacher';
        $assessment->method('save')->willReturnCallback(function () use ($assessment, &$savedStatuses): void {
            $savedStatuses[] = $assessment->status;
        });

        try {
            (new AssTaskGridCreation(new RecordingClaudeSonnetClient()))->processAssessment($assessment);
            $this->fail('Missing compiled subject must abort the task');
        } catch (WSException $exception) {
            $this->assertSame('Compiled subject is missing', $exception->getMessage());
        }

        $this->assertSame(['create_correction_grid'], $savedStatuses);
        $this->assertSame('create_correction_grid', $assessment->status);
    }

    public function testTaskFallsBackToTemplateInstructionsWhenNoneAreStored(): void
    {
        $compiled = '{"pages":["Cas pratique"]}';
        $compileKey = ObjectStore::assessmentSubjectCompileKey('school', 'teacher', 'ass-1');
        $gridKey = ObjectStore::assessmentSubjectCorrectionGridKey('school', 'teacher', 'ass-1');

        $store = $this->getMockBuilder(ObjectStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exists', 'getContents', 'putContents'])
            ->getMock();
        $store->method('exists')->willReturnCallback(static fn (string $key): bool => $key === $compileKey);
        $store->method('getContents')->willReturn($compiled);
        $store->method('putContents')->willReturn('etag');
        ObjectStore::setInstance($store);

        $assessment = $this->getMockBuilder(Assessment::class)
            ->onlyMethods(['save', 'instructionFilesText'])
            ->getMock();
        $assessment->id = 'ass-1';
        $assessment->school_id = 'school';
        $assessment->user_id = 'teacher';
        $assessment->method('instructionFilesText')->willReturn('');

        $client = new RecordingClaudeSonnetClient();
        $client->reply = json_encode(['instructions' => [], 'modificateurs_généraux' => [], 'parties' => []]);

        (new AssTaskGridCreation($client))->processAssessment($assessment);

        $this->assertStringContainsString("Instructions générales", $client->userText());
        $this->assertStringContainsString("Méthodologie du cas pratique", $client->userText());
        $this->assertStringContainsString($compiled, $client->userText());
    }

    private function installQueue(): void
    {
        $this->queued = [];
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'brPop', 'connect'])
            ->getMock();
        $redis->method('lPush')->willReturnCallback(function (string $key, string $payload): int {
            $this->queued[] = $payload;
            return 1;
        });
        RedisQueue::setInstance(new RedisQueue($redis));
    }
}

class RecordingClaudeSonnetClient extends ClaudeSonnetClient
{
    public string $reply = '{}';

    public function call_text(): string
    {
        return $this->reply;
    }

    public function systemContent(): string
    {
        foreach ($this->payload['messages'] as $message) {
            if (($message['role'] ?? '') === 'system') {
                return (string) $message['content'];
            }
        }
        return '';
    }

    public function userText(): string
    {
        foreach ($this->payload['messages'] as $message) {
            if (($message['role'] ?? '') !== 'user' || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    return (string) ($part['text'] ?? '');
                }
            }
        }
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return $this->payload['response_format']['json_schema']['schema'] ?? [];
    }
}
