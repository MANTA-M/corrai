<?php

namespace Corrai\Subject\DictationFranceCM2;

use Corrai\Model\BaseAssessment;
use Corrai\Model\File as BaseFile;
use Corrai\Queue\RedisQueue;
use Corrai\Subject\AssessmentFactory;
use Corrai\Utils\MenuLabels;
use Corrai\Utils\ObjectStore;
use Corrai\Utils\WSException;

/**
 * Dictation France CM2 submission state machine.
 *
 * Statuses: stored|correction_asked → ocr_done → errors_found → annotations → corrected
     */
class File extends BaseFile
{
    private const OCR_LANG = 'fr';

    /**
     * Statuses added by this subject, on top of the base file labels.
     *
     * @var array<string, array<string, string>>
     */
    private const EXTRA_STATUS_LABELS = [
        'ocr_done' => [
            'en' => 'OCR done',
            'fr' => 'OCR terminé',
            'ru' => 'OCR выполнен',
            'uk' => 'OCR виконано',
            'es' => 'OCR terminado',
            'pt' => 'OCR concluído',
            'ro' => 'OCR finalizat',
            'de' => 'OCR abgeschlossen',
        ],
        'errors_found' => [
            'en' => 'Errors found',
            'fr' => 'Erreurs trouvées',
            'ru' => 'Ошибки найдены',
            'uk' => 'Помилки знайдено',
            'es' => 'Errores encontrados',
            'pt' => 'Erros encontrados',
            'ro' => 'Erori găsite',
            'de' => 'Fehler gefunden',
        ],
        'annotations' => [
            'en' => 'Annotations',
            'fr' => 'Annotations',
            'ru' => 'Аннотации',
            'uk' => 'Анотації',
            'es' => 'Anotaciones',
            'pt' => 'Anotações',
            'ro' => 'Adnotări',
            'de' => 'Anmerkungen',
        ],
    ];

    public function get_status_label(?string $locale = null): string
    {
        $labels = self::EXTRA_STATUS_LABELS[$this->status] ?? null;
        if (!is_array($labels)) {
            return parent::get_status_label($locale);
        }
        return MenuLabels::pick($labels, MenuLabels::locale($locale), $this->status);
    }

    public function on_stored(): void
    {
        if ($this->type !== 'submission') {
            return;
        }

        $this->appendEvent('OCR queued');
        RedisQueue::getInstance()->enqueueOcr($this->contentKey(), self::OCR_LANG);
    }

    public function on_correction_asked(): void
    {
        $this->on_stored();
    }

    public function on_ocr_done(): void
    {
        (new CorrectingTask())->correct($this);
    }

    public function on_errors_found(): void
    {
        (new AnnotatingTask())->annotate($this);
    }

    public function on_annotations(): void
    {
        (new RenderingTask())->render($this);
    }
}
