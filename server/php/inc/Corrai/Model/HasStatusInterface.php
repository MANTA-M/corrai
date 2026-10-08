<?php

namespace Corrai\Model;

/**
 * Object that has a status and a localized status label.
 */
interface HasStatusInterface
{
    public function get_status(): string;

    public function get_status_label(?string $locale = null): string;

    /**
     * Every state of this object, as key => label in the queried locale.
     *
     * @return array<string, string>
     */
    public static function statusLabels(?string $locale = null): array;
}
