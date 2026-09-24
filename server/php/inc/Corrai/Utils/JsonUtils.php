<?php

namespace Corrai\Utils;

use Exception;
use JsonException;

class JsonUtils
{
    public static function decodeStrict(?string $json, bool $assoc = true, int $depth = 512, int $options = 0): mixed
    {
        if (!$json) {
            return null;
        }
        try {
            return json_decode($json, $assoc, $depth, $options | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new Exception($e->getMessage(), $e->getCode(), $e);
        }
    }

    public static function decodeObj(?string $json, int $depth = 512, int $options = 0): ?object
    {
        return self::decodeStrict($json, false, $depth, $options);
    }

    public static function decodeArray(?string $json, int $depth = 512, int $options = 0): ?array
    {
        return self::decodeStrict($json, true, $depth, $options);
    }
}
