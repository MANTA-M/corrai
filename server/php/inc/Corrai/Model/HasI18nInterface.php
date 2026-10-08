<?php

namespace Corrai\Model;

/**
 * Object that can provide labels values in multiple languages.
 */
interface HasI18nInterface
{
    /**
     * Multilingual label definitions for this object, or translations for a specific key.
     *
     * @return array<string, mixed>
     */
    public function get_i18n(?string $key = null): array;
}
