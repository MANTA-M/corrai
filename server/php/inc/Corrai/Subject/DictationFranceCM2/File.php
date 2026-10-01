<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\File as BaseFile;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\AssessmentFactory;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;
use Throwable;

/**
     * Dictation France CM2 submission state machine.
     *
     * Statuses: loaded|correction_asked → ocr_done → errors_found → directives → corrected
     */
class File extends BaseFile
{
    private const OCR_LANG = 'fr';
    private const LANGUAGE_NAME = 'French';

    public function on_loaded(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $this->appendEvent('OCR queued');
        RedisQueue::getInstance()->enqueueOcr($this->contentKey(), self::OCR_LANG);
    }

    public function on_correction_asked(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $assessment = $this->loadAssessment();
        $assessment->deleteFilesOfType('debug', $this->student ?? '');

        $this->appendEvent('OCR queued');
        RedisQueue::getInstance()->enqueueOcr($this->contentKey(), self::OCR_LANG);
    }

    public function on_ocr_done(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $copyPath = null;
        $solutionPath = null;
        try {
            $assessment = $this->loadAssessment();
            $store = ObjectStore::getInstance();
            $ocrKey = $this->ocrResultKey();
            if (!$store->exists($ocrKey)) {
                throw new WSException('OCR result is missing for this file', 400);
            }
            $ocrRaw = $store->getContents($ocrKey);
            $ocrWords = json_decode($ocrRaw, true);
            if (!is_array($ocrWords)) {
                throw new WSException('Invalid OCR result JSON', 400);
            }

            $copyPath = $store->downloadToTemp($this->contentKey());
            $solution = $this->firstSolutionFile($assessment);
            $solutionPath = $store->downloadToTemp($assessment->fileContentKey($solution['id']));

            $pipeline = new Pipeline();
            $correction = $pipeline->findErrors(
                $assessment,
                $copyPath,
                $this->name,
                $solutionPath,
                $solution['name'],
                self::LANGUAGE_NAME,
                $ocrWords
            );

            $store->putContents(
                $this->foundErrorsKey(),
                $correction,
                'application/json'
            );

            $this->status = 'errors_found';
            $this->saveAttributes();
            $this->appendEvent('Errors found');
            RedisQueue::getInstance()->enqueueFile($this->id);
        } catch (Throwable $th) {
            $this->failCorrection($th);
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
            if ($solutionPath !== null) {
                @unlink($solutionPath);
            }
        }
    }

    public function on_errors_found(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $copyPath = null;
        try {
            $store = ObjectStore::getInstance();
            $errorsKey = $this->foundErrorsKey();
            if (!$store->exists($errorsKey)) {
                throw new WSException('Errors JSON is missing for this file', 400);
            }
            $correction = $store->getContents($errorsKey);
            $copyPath = $store->downloadToTemp($this->contentKey());

            $pipeline = new Pipeline();
            $directivesPhp = $pipeline->gdDirectives($copyPath, $this->name, $correction);

            $store->putContents(
                $this->markupDirectivesKey(),
                $directivesPhp,
                'text/plain; charset=utf-8'
            );

            $this->status = 'directives';
            $this->saveAttributes();
            $this->appendEvent('Directives written');
            RedisQueue::getInstance()->enqueueFile($this->id);
        } catch (Throwable $th) {
            $this->failCorrection($th);
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
        }
    }

    public function on_directives(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $copyPath = null;
        try {
            $assessment = $this->loadAssessment();
            $store = ObjectStore::getInstance();
            $directivesKey = $this->markupDirectivesKey();
            if (!$store->exists($directivesKey)) {
                throw new WSException('Directives are missing for this file', 400);
            }
            $directivesPhp = $store->getContents($directivesKey);
            $copyPath = $store->downloadToTemp($this->contentKey());

            $pipeline = new Pipeline();
            $png = $pipeline->renderCorrection($copyPath, $directivesPhp);

            $student = $this->student ?? '';
            $assessment->deleteFilesOfType('correction', $student);
            $base = pathinfo($this->name, PATHINFO_FILENAME);
            $assessment->createFile(
                $base . ' correction.png',
                $png,
                'image/png',
                'correction',
                $student !== '' ? $student : null
            );

            $this->status = 'corrected';
            $this->saveAttributes();
            $this->appendEvent('Correction rendered');
        } catch (Throwable $th) {
            $this->failCorrection($th);
        } finally {
            if ($copyPath !== null) {
                @unlink($copyPath);
            }
        }
    }

    private function loadAssessment(): \Corrai\Model\BaseAssessment
    {
        $store = ObjectStore::getInstance();
        $assessmentAttrKey = ObjectStore::assessmentAttrKey($this->school_id, $this->user_id, $this->assessment_id);
        if (!$store->exists($assessmentAttrKey)) {
            throw new WSException('Assessment does not exist for this file', 404);
        }
        $loaded = $store->getJson($assessmentAttrKey);

        return AssessmentFactory::fromAttributes(
            $loaded['data'],
            $this->school_id,
            $this->user_id,
            $this->assessment_id
        );
    }

    /**
     * @return array{id: string, name: string, type: string, student: ?string}
     */
    private function firstSolutionFile(\Corrai\Model\BaseAssessment $assessment): array
    {
        foreach ($assessment->list_files() as $file) {
            if (($file['type'] ?? '') === 'solution') {
                return $file;
            }
        }
        throw new WSException('No corrigé file on this assessment', 400);
    }

    private function failCorrection(Throwable $th): void
    {
        error_log('[DictationFranceCM2] ' . $th);
        try {
            $this->appendEvent('Correction failed');
            $this->status = 'error';
            $this->saveAttributes();
        } catch (Throwable $ignore) {
            // Best-effort status update
        }
        if ($th instanceof WSException) {
            throw $th;
        }
        throw new WSException($th->getMessage(), 400, $th);
    }
}
