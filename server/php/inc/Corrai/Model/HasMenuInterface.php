<?php

namespace Corrai\Model;

/**
 * Object that can be displayed and acted upon in a menu.
 */
interface HasMenuInterface
{
    /**
     * Actions shown on the interface for this object.
     *
     * Text buttons leave icon empty. Start correction appears only when a submission exists.
     *
     * @return array<int, array{key: string, label: string, icon: string, color: string}>
     */
    public function get_menu(?string $locale = null): array;
}
