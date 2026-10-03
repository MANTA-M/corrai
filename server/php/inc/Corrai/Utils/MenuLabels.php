<?php

namespace Corrai\Utils;

/**
 * Localized menu labels shared by assessments and files.
 */
class MenuLabels
{
    public const BLUE = '#1a55e8';
    public const GREEN = '#20835a';
    public const DANGER = '#c93b45';
    public const MUTED = '#5d6680';

    /** @var list<string> */
    public const CODES = ['en', 'fr', 'ru', 'uk', 'es', 'pt', 'ro', 'de'];

    /**
     * @var array<string, array<string, string>>
     */
    private const TEXT = [
        'edit' => [
            'en' => 'Edit',
            'fr' => 'Modifier',
            'ru' => 'Редактировать',
            'uk' => 'Редагувати',
            'es' => 'Editar',
            'pt' => 'Editar',
            'ro' => 'Editează',
            'de' => 'Bearbeiten',
        ],
        'delete' => [
            'en' => 'Delete assessment',
            'fr' => 'Supprimer l\'évaluation',
            'ru' => 'Удалить оценивание',
            'uk' => 'Видалити оцінювання',
            'es' => 'Eliminar evaluación',
            'pt' => 'Eliminar avaliação',
            'ro' => 'Șterge evaluarea',
            'de' => 'Bewertung löschen',
        ],
        'edit_subject' => [
            'en' => 'Edit subject',
            'fr' => 'Modifier le sujet',
            'ru' => 'Изменить задание',
            'uk' => 'Змінити завдання',
            'es' => 'Modificar el tema',
            'pt' => 'Editar o enunciado',
            'ro' => 'Modifică subiectul',
            'de' => 'Thema bearbeiten',
        ],
        'add_copies' => [
            'en' => 'Add copies',
            'fr' => 'Ajouter des copies',
            'ru' => 'Добавить работы',
            'uk' => 'Додати роботи',
            'es' => 'Añadir copias',
            'pt' => 'Adicionar provas',
            'ro' => 'Adaugă lucrări',
            'de' => 'Arbeiten hinzufügen',
        ],
        'start_correction' => [
            'en' => 'Start correction',
            'fr' => 'Lancer la correction',
            'ru' => 'Запустить проверку',
            'uk' => 'Запустити перевірку',
            'es' => 'Iniciar la corrección',
            'pt' => 'Iniciar a correção',
            'ro' => 'Lansează corectarea',
            'de' => 'Korrektur starten',
        ],
        'view' => [
            'en' => 'View',
            'fr' => 'Voir',
            'ru' => 'Просмотр',
            'uk' => 'Переглянути',
            'es' => 'Ver',
            'pt' => 'Ver',
            'ro' => 'Vezi',
            'de' => 'Anzeigen',
        ],
        'student_open' => [
            'en' => 'View student',
            'fr' => 'Voir l\'élève',
            'ru' => 'Открыть ученика',
            'uk' => 'Відкрити учня',
            'es' => 'Ver al alumno',
            'pt' => 'Ver aluno',
            'ro' => 'Vezi elevul',
            'de' => 'Schüler anzeigen',
        ],
        'student_rename' => [
            'en' => 'Rename',
            'fr' => 'Renommer',
            'ru' => 'Переименовать',
            'uk' => 'Перейменувати',
            'es' => 'Renombrar',
            'pt' => 'Renomear',
            'ro' => 'Redenumește',
            'de' => 'Umbenennen',
        ],
        'student_delete' => [
            'en' => 'Delete',
            'fr' => 'Supprimer',
            'ru' => 'Удалить',
            'uk' => 'Видалити',
            'es' => 'Eliminar',
            'pt' => 'Eliminar',
            'ro' => 'Șterge',
            'de' => 'Löschen',
        ],
        'edit_text' => [
            'en' => 'Edit',
            'fr' => 'Éditer',
            'ru' => 'Редактировать',
            'uk' => 'Редагувати',
            'es' => 'Editar',
            'pt' => 'Editar',
            'ro' => 'Editează',
            'de' => 'Bearbeiten',
        ],
        'reassign' => [
            'en' => 'Reassign',
            'fr' => 'Réaffecter',
            'ru' => 'Переназначить',
            'uk' => 'Перепризначити',
            'es' => 'Reasignar',
            'pt' => 'Reatribuir',
            'ro' => 'Reatribuie',
            'de' => 'Neu zuordnen',
        ],
        'events' => [
            'en' => 'Events',
            'fr' => 'Événements',
            'ru' => 'События',
            'uk' => 'Події',
            'es' => 'Eventos',
            'pt' => 'Eventos',
            'ro' => 'Evenimente',
            'de' => 'Ereignisse',
        ],
        'rename' => [
            'en' => 'Rename',
            'fr' => 'Renommer',
            'ru' => 'Переименовать',
            'uk' => 'Перейменувати',
            'es' => 'Renombrar',
            'pt' => 'Mudar o nome',
            'ro' => 'Redenumește',
            'de' => 'Umbenennen',
        ],
        'file_delete' => [
            'en' => 'Delete',
            'fr' => 'Supprimer',
            'ru' => 'Удалить',
            'uk' => 'Видалити',
            'es' => 'Eliminar',
            'pt' => 'Eliminar',
            'ro' => 'Șterge',
            'de' => 'Löschen',
        ],
        'correct' => [
            'en' => 'Correct',
            'fr' => 'Corriger',
            'ru' => 'Проверить',
            'uk' => 'Перевірити',
            'es' => 'Corregir',
            'pt' => 'Corrigir',
            'ro' => 'Corectează',
            'de' => 'Korrigieren',
        ],
        'file_type_subject' => [
            'en' => 'Subject',
            'fr' => 'Sujet',
            'ru' => 'Задание',
            'uk' => 'Завдання',
            'es' => 'Enunciado',
            'pt' => 'Enunciado',
            'ro' => 'Subiect',
            'de' => 'Aufgabenstellung',
        ],
        'file_type_solution' => [
            'en' => 'Solution',
            'fr' => 'Corrigé',
            'ru' => 'Решение',
            'uk' => 'Розв\'язок',
            'es' => 'Solución',
            'pt' => 'Solução',
            'ro' => 'Rezolvare',
            'de' => 'Lösung',
        ],
        'file_type_submission' => [
            'en' => 'Submission',
            'fr' => 'Copie',
            'ru' => 'Работа',
            'uk' => 'Робота',
            'es' => 'Entrega',
            'pt' => 'Submissão',
            'ro' => 'Lucrare',
            'de' => 'Abgabe',
        ],
        'file_type_instructions' => [
            'en' => 'Instructions',
            'fr' => 'Consignes',
            'ru' => 'Инструкции',
            'uk' => 'Інструкції',
            'es' => 'Instrucciones',
            'pt' => 'Instruções',
            'ro' => 'Instrucțiuni',
            'de' => 'Hinweise',
        ],
        'file_type_correction' => [
            'en' => 'Correction',
            'fr' => 'Correction',
            'ru' => 'Проверка',
            'uk' => 'Перевірка',
            'es' => 'Corrección',
            'pt' => 'Correção',
            'ro' => 'Corecție',
            'de' => 'Korrektur',
        ],
        'file_type_debug' => [
            'en' => 'Debug',
            'fr' => 'Debug',
            'ru' => 'Отладка',
            'uk' => 'Налагодження',
            'es' => 'Debug',
            'pt' => 'Debug',
            'ro' => 'Debug',
            'de' => 'Debug',
        ],
        'file_stored' => [
            'en' => 'Stored',
            'fr' => 'Stocké',
            'ru' => 'Сохранено',
            'uk' => 'Збережено',
            'es' => 'Almacenado',
            'pt' => 'Armazenado',
            'ro' => 'Stocat',
            'de' => 'Gespeichert',
        ],
        'file_type_unknown' => [
            'en' => 'Unknown',
            'fr' => 'Inconnu',
            'ru' => 'Неизвестно',
            'uk' => 'Невідомо',
            'es' => 'Desconocido',
            'pt' => 'Desconhecido',
            'ro' => 'Necunoscut',
            'de' => 'Unbekannt',
        ],
    ];

    /**
     * A supported locale. Missing or unknown values fall back to French.
     * An empty value uses the locale query parameter when the call comes from a request.
     */
    public static function locale(?string $locale = null): string
    {
        if ($locale === null || trim($locale) === '') {
            $fromRequest = Request::getStringParam('locale');
            $locale = is_string($fromRequest) ? $fromRequest : '';
        }
        $locale = strtolower(trim($locale));
        $dash = strpos($locale, '-');
        if ($dash !== false) {
            $locale = substr($locale, 0, $dash);
        }
        if (!in_array($locale, self::CODES, true)) {
            return 'fr';
        }
        return $locale;
    }

    /**
     * @param array<string, string> $labels
     */
    public static function pick(array $labels, string $locale, string $fallback): string
    {
        $locale = self::locale($locale);
        $chosen = $labels[$locale] ?? '';
        if (is_string($chosen) && $chosen !== '') {
            return $chosen;
        }
        $english = $labels['en'] ?? '';
        if (is_string($english) && $english !== '') {
            return $english;
        }
        return $fallback;
    }

    public static function text(string $key, string $locale): string
    {
        return self::pick(self::TEXT[$key] ?? [], $locale, $key);
    }

    /**
     * Display name of a file event. The stored token stays English.
     */
    public static function fileEventLabel(string $name, ?string $locale = null): string
    {
        if ($name === 'Loaded' || $name === 'Stored') {
            return self::text('file_stored', self::locale($locale));
        }
        return $name;
    }

    /**
     * @return array{key: string, label: string, icon: string, color: string}
     */
    public static function item(
        string $key,
        string $locale,
        string $icon,
        string $color,
        ?string $labelKey = null
    ): array {
        return [
            'key' => $key,
            'label' => self::text($labelKey ?? $key, $locale),
            'icon' => $icon,
            'color' => $color,
        ];
    }
}
