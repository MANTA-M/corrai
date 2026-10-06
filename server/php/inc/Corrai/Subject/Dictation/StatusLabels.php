<?php

namespace Corrai\Subject\Dictation;

/**
 * File states shared by dictation subjects, on top of the base file labels.
 */
class StatusLabels
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function table(): array
    {
        return [
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
    }
}
