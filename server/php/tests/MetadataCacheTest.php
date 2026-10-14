<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\S3File;
use Corrai\Utils\Store\MetadataCache;
use PHPUnit\Framework\TestCase;
use Redis;

class MetadataCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        MetadataCache::setInstance(null);
        parent::tearDown();
    }

    public function testKeyBuilders(): void
    {
        $cache = new MetadataCache($this->redisMock());
        $this->assertSame(
            'corrai:meta:v1:sch:tea:assessments',
            $cache->assessmentsIndexKey('sch', 'tea')
        );
        $this->assertSame(
            'corrai:meta:v1:sch:tea:a:asm',
            $cache->assessmentKey('sch', 'tea', 'asm')
        );
        $this->assertSame(
            'corrai:meta:v1:sch:tea:a:asm:gen',
            $cache->assessmentGenKey('sch', 'tea', 'asm')
        );
    }

    public function testGetAssessmentTreeReturnsNullOnMiss(): void
    {
        $redis = $this->redisMock(['get', 'connect']);
        $redis->method('get')->willReturn(false);

        $cache = new MetadataCache($redis);
        $this->assertNull($cache->getAssessmentTree('sch', 'tea', 'asm'));
    }

    public function testGetAssessmentTreeDecodesJson(): void
    {
        $tree = ['attrs' => ['id' => 'asm', 'name' => 'Test']];
        $redis = $this->redisMock(['get', 'connect']);
        $redis->expects($this->once())
            ->method('get')
            ->with('corrai:meta:v1:sch:tea:a:asm')
            ->willReturn(json_encode($tree));

        $cache = new MetadataCache($redis);
        $this->assertSame($tree, $cache->getAssessmentTree('sch', 'tea', 'asm'));
    }

    public function testSetAssessmentTreeRespectsGeneration(): void
    {
        $redis = $this->redisMock(['get', 'setex', 'sAdd', 'connect']);
        $redis->method('get')->willReturn('3');
        $redis->expects($this->never())->method('setex');

        $cache = new MetadataCache($redis);
        $ok = $cache->setAssessmentTree('sch', 'tea', 'asm', ['attrs' => []], 2);
        $this->assertFalse($ok);
    }

    public function testSetAssessmentTreeStoresWhenGenMatches(): void
    {
        $redis = $this->redisMock(['get', 'setex', 'sAdd', 'connect']);
        $redis->method('get')->willReturn('2');
        $redis->expects($this->once())
            ->method('setex')
            ->with(
                'corrai:meta:v1:sch:tea:a:asm',
                MetadataCache::TTL_SECONDS,
                $this->callback(static function (string $payload): bool {
                    $decoded = json_decode($payload, true);
                    return is_array($decoded) && ($decoded['attrs']['name'] ?? '') === 'Exam';
                })
            )
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('sAdd')
            ->with('corrai:meta:v1:sch:tea:aids', 'asm')
            ->willReturn(1);

        $cache = new MetadataCache($redis);
        $ok = $cache->setAssessmentTree(
            'sch',
            'tea',
            'asm',
            ['attrs' => ['name' => 'Exam']],
            2
        );
        $this->assertTrue($ok);
    }

    public function testInvalidateAssessmentIncrementsGenAndDeletes(): void
    {
        $redis = $this->redisMock(['incr', 'del', 'connect']);
        $redis->expects($this->once())
            ->method('incr')
            ->with('corrai:meta:v1:sch:tea:a:asm:gen')
            ->willReturn(1);
        $redis->expects($this->once())
            ->method('del')
            ->with('corrai:meta:v1:sch:tea:a:asm')
            ->willReturn(1);

        $cache = new MetadataCache($redis);
        $cache->invalidateAssessment('sch', 'tea', 'asm');
    }

    public function testInvalidateTeacherClearsIndexAndAssessmentKeys(): void
    {
        $redis = $this->redisMock(['sMembers', 'incr', 'del', 'connect']);
        $redis->method('sMembers')
            ->with('corrai:meta:v1:sch:tea:aids')
            ->willReturn(['a1', 'a2']);
        $redis->expects($this->exactly(2))->method('incr')->willReturn(1);
        $redis->expects($this->exactly(4))->method('del')->willReturn(1);

        $cache = new MetadataCache($redis);
        $cache->invalidateTeacher('sch', 'tea');
    }

    public function testHydrateFileModelsFromTree(): void
    {
        $cache = new MetadataCache($this->redisMock());
        $tree = [
            'attrs' => ['id' => 'asm'],
            'subject' => [
                [
                    'attrs' => [
                        'id' => 'fsubj',
                        'name' => 'sujet.pdf',
                        'class' => 'Corrai\\Model\\SubjectFile',
                        'status' => 'stored',
                        'content_type' => 'application/pdf',
                        'size' => 10,
                        'created' => 1,
                        'thumbnail' => false,
                    ],
                    'directory_s3' => [['id' => 'attributes.json', 'name' => 'attributes.json']],
                ],
            ],
            'unclassified' => [],
            'legacy' => [],
            'blobs' => [
                [
                    'id' => 'b1',
                    'name' => 'solution.pdf',
                    'key' => 'schools/sch/teachers/tea/assessments/asm/blobs/b1',
                    'type' => 'solution',
                    'student' => null,
                    'status' => '',
                    'content_type' => 'application/pdf',
                    'size' => 5,
                    'created' => 2,
                    'school_id' => 'sch',
                    'user_id' => 'tea',
                    'assessment_id' => 'asm',
                ],
            ],
            'students' => [
                [
                    'attrs' => [
                        'id' => 'stu1',
                        'name' => 'Alice',
                        'status' => 'pending',
                        'mark' => null,
                        'appreciation' => '',
                    ],
                    'input_files' => [
                        [
                            'attrs' => [
                                'id' => 'fsub',
                                'name' => 'copie.pdf',
                                'class' => 'Corrai\\Model\\SubmissionFile',
                                'student' => 'stu1',
                                'status' => 'stored',
                                'content_type' => 'application/pdf',
                                'size' => 3,
                                'created' => 3,
                                'thumbnail' => false,
                            ],
                            'directory_s3' => [],
                        ],
                    ],
                    'direct_s3' => [
                        [
                            'id' => 'corr1',
                            'name' => 'correction.png',
                            'key' => 'schools/sch/teachers/tea/assessments/asm/students/stu1/correction.png',
                            'type' => 'correction',
                            'student' => 'stu1',
                            'status' => '',
                            'content_type' => 'image/png',
                            'size' => 9,
                            'created' => 4,
                            'school_id' => 'sch',
                            'user_id' => 'tea',
                            'assessment_id' => 'asm',
                        ],
                    ],
                ],
            ],
        ];

        $files = $cache->hydrateFileModels($tree, 'sch', 'tea', 'asm');
        $ids = array_map(static fn($f) => $f->id, $files);
        sort($ids);
        $this->assertSame(['b1', 'corr1', 'fsub', 'fsubj'], $ids);

        $students = $cache->hydrateStudentModels(
            $tree,
            'sch',
            'tea',
            'asm',
            \Corrai\Model\Student::class
        );
        $this->assertCount(1, $students);
        $this->assertSame('Alice', $students[0]->name);

        $subject = $cache->hydrateSubjectFileModels($tree, 'sch', 'tea', 'asm');
        $subjectIds = array_map(static fn($f) => $f->id, $subject);
        sort($subjectIds);
        $this->assertSame(['b1', 'fsubj'], $subjectIds);

        $studentFiles = $cache->hydrateStudentFileModels($tree, 'sch', 'tea', 'asm', 'stu1');
        $this->assertCount(2, $studentFiles);
        foreach ($studentFiles as $studentFile) {
            $this->assertTrue(
                $studentFile instanceof \Corrai\Model\InputFile || $studentFile instanceof S3File
            );
        }
    }

    public function testS3FileCacheRoundTrip(): void
    {
        $file = new S3File();
        $file->id = 'xid';
        $file->name = 'n.pdf';
        $file->key = 'schools/s/teachers/t/assessments/a/blobs/xid';
        $file->type = 'solution';
        $file->school_id = 's';
        $file->user_id = 't';
        $file->assessment_id = 'a';
        $file->size = 12;
        $file->created = 99;

        $restored = S3File::fromCachedArray($file->toCacheArray());
        $this->assertSame('xid', $restored->id);
        $this->assertSame('n.pdf', $restored->name);
        $this->assertSame('solution', $restored->type);
        $this->assertSame(12, $restored->size);
    }

    /**
     * @param list<string> $methods
     */
    private function redisMock(array $methods = ['connect']): Redis
    {
        return $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }
}
