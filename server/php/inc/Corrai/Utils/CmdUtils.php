<?php

namespace Corrai\Utils;

use BeBat\ConsoleColor\Style;
use BeBat\ConsoleColor\Style\Color;
use BeBat\ConsoleColor\StyleInterface;


class CmdUtils
{
    public static function print_styled(string $text, StyleInterface $style): int|false
    {
        $cmd_out_handle = fopen('php://stdout', 'w');
        $cmd_style_object = new Style($cmd_out_handle);

        return fwrite($cmd_out_handle, $cmd_style_object->apply(
            "$text\n",
            $style
        ));
    }

    public static function print_info(string $text): int|false
    {
        return self::print_styled($text, Color::BrightBlue);
    }

    public static function print_warn(string $text): int|false
    {
        return self::print_styled($text, Color::Yellow);
    }

    public static function print_error(string $text): int|false
    {
        return self::print_styled($text, Color::Red);
    }
}
