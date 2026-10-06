<?php

declare(strict_types=1);

namespace Corrai\Tests;

use Corrai\Model\School;
use Corrai\Model\User;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\DictationFranceCM2\Assessment;
use Corrai\Utils\CsvTreeMigrator;
use Corrai\Utils\Store\HashId;
use Corrai\Utils\Store\ObjectStore;
use PHPUnit\Framework\TestCase;
use Redis;

class DictationFranceCM2InstructionTemplateTest extends TestCase
{
    private static School $school;
    private User $user;

    public static function setUpBeforeClass(): void
    {
        (new CsvTreeMigrator())->run();
        self::$school = School::ensureIndependent();
    }

    protected function setUp(): void
    {
        $redis = $this->getMockBuilder(Redis::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lPush', 'brPop', 'connect'])
            ->getMock();
        $redis->method('lPush')->willReturn(1);
        RedisQueue::setInstance(new RedisQueue($redis));

        $suffix = bin2hex(random_bytes(4));
        $this->user = self::$school->addUser(
            "teacher_{$suffix}@ind.test",
            "[Test] Teacher {$suffix}",
            'test-password-' . $suffix,
            User::ROLE_TEACHER
        );
    }

    protected function tearDown(): void
    {
        RedisQueue::setInstance(null);
        if (isset($this->user) && $this->user->id !== null) {
            try {
                $this->user->delete();
            } catch (\Throwable $e) {
                // Ignore cleanup error
            }
        }
    }

    public function testTemplateInstructionPathFindsExistingTemplate(): void
    {
        $assessment = new Assessment();
        $path = $assessment->templateInstructionPath('fr');
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertStringContainsString('template_instruction', basename($path));
    }

    public function testTemplateInstructionPathPrefersLocaleSpecificFile(): void
    {
        $assessment = new Assessment();
        $dir = dirname((new \ReflectionClass($assessment))->getFileName());

        // Create a temporary locale-specific template
        $tempLocaleFile = $dir . '/template_instruction_xx.md';
        file_put_contents($tempLocaleFile, 'Custom XX instructions');

        try {
            $path = $assessment->templateInstructionPath('xx');
            $this->assertSame($tempLocaleFile, $path);

            // For another locale that doesn't have a specific file, falls back to default template
            $fallbackPath = $assessment->templateInstructionPath('yy');
            $this->assertNotNull($fallbackPath);
            $this->assertNotSame($tempLocaleFile, $fallbackPath);
        } finally {
            if (file_exists($tempLocaleFile)) {
                unlink($tempLocaleFile);
            }
        }
    }

    public function testTemplateInstructionPathPrefersSingularWhenBothExist(): void
    {
        $assessment = new Assessment();
        $dir = dirname((new \ReflectionClass($assessment))->getFileName());

        $tempSingular = $dir . '/template_instruction_zz.md';
        $tempPlural = $dir . '/template_instructions_zz.md';
        file_put_contents($tempSingular, 'Singular ZZ');
        file_put_contents($tempPlural, 'Plural ZZ');

        try {
            $path = $assessment->templateInstructionPath('zz');
            $this->assertSame($tempSingular, $path);
        } finally {
            if (file_exists($tempSingular)) {
                unlink($tempSingular);
            }
            if (file_exists($tempPlural)) {
                unlink($tempPlural);
            }
        }
    }

    public function testSavingNewAssessmentCreatesInstructionFileFromTemplate(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Dictation CM2';
        $assessment->id = HashId::create();
        $assessment->correction_language = 'fr';
        $assessment->save();

        try {
            $files = $assessment->listFileModels();
            $instructionFiles = array_values(array_filter($files, fn($f) => $f->type === 'instructions'));

            $this->assertCount(1, $instructionFiles);
            $instructionFile = $instructionFiles[0];

            $this->assertSame('instructions.md', $instructionFile->name);
            $this->assertSame('instructions', $instructionFile->type);

            $store = ObjectStore::getInstance();
            $content = $store->getContents($instructionFile->contentKey());
            $templateContent = file_get_contents($assessment->templateInstructionPath('fr'));

            $this->assertSame($templateContent, $content);

            // Calling save() again should not create duplicate instruction files
            $assessment->name = '[Test] Updated Assessment Name';
            $assessment->save();

            $filesAfter = $assessment->listFileModels();
            $instructionFilesAfter = array_values(array_filter($filesAfter, fn($f) => $f->type === 'instructions'));
            $this->assertCount(1, $instructionFilesAfter);
            $this->assertSame($instructionFile->id, $instructionFilesAfter[0]->id);
        } finally {
            $assessment->delete();
        }
    }

    public function testPreExistingInstructionFileIsNotOverwritten(): void
    {
        $assessment = new Assessment();
        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->name = '[Test] Custom Instructions Assessment';
        $assessment->id = HashId::create();

        // Save assessment with custom instructions already created
        $assessment->save();

        // Update instruction file content
        $instructionFiles = array_values(array_filter($assessment->listFileModels(), fn($f) => $f->type === 'instructions'));
        $this->assertCount(1, $instructionFiles);
        $file = $instructionFiles[0];

        $customContent = "My custom teacher instructions";
        $assessment->writeFileContents($file->id, $customContent);

        // Re-saving the assessment must preserve the custom content
        $assessment->save();

        $contentAfter = ObjectStore::getInstance()->getContents($file->contentKey());
        $this->assertSame($customContent, $contentAfter);

        $assessment->delete();
    }

    public function testAssessmentCreationViaFactoryGeneratesInstructionFile(): void
    {
        $payload = [
            'name' => '[Test] Dictation CM2 from POST',
            'subject' => 'Dictation',
            'country' => 'fr',
            'level' => 'CM2',
            'correction_language' => 'fr',
        ];

        $class = \Corrai\Subject\AssessmentFactory::assessmentClass(
            $payload['subject'],
            $payload['country'],
            $payload['level']
        );
        $this->assertSame(Assessment::class, $class);

        $assessment = $class::from_array($payload);
        $this->assertInstanceOf(Assessment::class, $assessment);

        $assessment->school_id = $this->user->school_id;
        $assessment->user_id = $this->user->id;
        $assessment->save();

        try {
            $files = $assessment->listFileModels();
            $instructionFiles = array_values(array_filter($files, fn($f) => $f->type === 'instructions'));
            $this->assertCount(1, $instructionFiles);
            $this->assertSame('instructions.md', $instructionFiles[0]->name);
            $this->assertSame('instructions', $instructionFiles[0]->type);
        } finally {
            $assessment->delete();
        }
    }
}
