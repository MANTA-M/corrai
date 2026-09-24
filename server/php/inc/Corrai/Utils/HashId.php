<?php

namespace Corrai\Utils;

use Exception;

/**
 * Short alphanumeric identifiers used as S3 tree node names.
 */
class HashId
{
    public const LENGTH = 7;
    private const CHARS = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Generate a unique 7-character id that is not already registered in _id/.
     *
     * @throws Exception If a unique ID could not be generated after maxAttempts
     */
    public static function create(int $maxAttempts = 1000): string
    {
        $store = ObjectStore::getInstance();
        $attempts = 0;

        do {
            $hash = '';
            $randomData = random_bytes(self::LENGTH);
            for ($i = 0; $i < self::LENGTH; $i++) {
                $hash .= self::CHARS[ord($randomData[$i]) % strlen(self::CHARS)];
            }
            $attempts++;

            if ($attempts >= $maxAttempts) {
                throw new Exception("Could not generate unique ID after $maxAttempts attempts");
            }
        } while ($store->exists(ObjectStore::idIndexKey($hash)));

        return $hash;
    }

    /**
     * Validate that a string looks like a HashId.
     */
    public static function isValid(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-zA-Z]{' . self::LENGTH . '}$/', $id);
    }
}
