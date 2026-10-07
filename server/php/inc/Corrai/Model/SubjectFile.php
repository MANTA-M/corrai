<?php

namespace Corrai\Model;

/**
 * Subject material given to students. No correction pipeline.
 *
 * Page images: ocr_asked → ocr → ocr_refine (when the page is rotated and
 * cropped) → ocr_done.
 */
class SubjectFile extends InputFile
{
    public function on_stored(): void
    {
    }

    protected static function statusLabelTable(): array
    {
        return array_merge(parent::statusLabelTable(), [
            'ocr_asked' => [
                'en' => 'OCR requested',
                'fr' => 'OCR demandé',
                'ru' => 'OCR запрошен',
                'uk' => 'OCR запитано',
                'es' => 'OCR solicitado',
                'pt' => 'OCR pedido',
                'ro' => 'OCR cerut',
                'de' => 'OCR angefordert',
            ],
            'ocr' => [
                'en' => 'OCR',
                'fr' => 'OCR',
                'ru' => 'OCR',
                'uk' => 'OCR',
                'es' => 'OCR',
                'pt' => 'OCR',
                'ro' => 'OCR',
                'de' => 'OCR',
            ],
            'ocr_refine' => [
                'en' => 'OCR refine',
                'fr' => 'OCR refine',
                'ru' => 'OCR refine',
                'uk' => 'OCR refine',
                'es' => 'OCR refine',
                'pt' => 'OCR refine',
                'ro' => 'OCR refine',
                'de' => 'OCR refine',
            ],
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
        ]);
    }
}
