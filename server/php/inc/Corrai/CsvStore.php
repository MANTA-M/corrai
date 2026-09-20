<?php

namespace Corrai;

use Exception;

/**
 * Encode / decode a single-record CSV (header row + one data row).
 */
class CsvStore
{
    /**
     * Encode an associative array as CSV with a header row and one data row.
     *
     * @param array<string, scalar|null> $row
     */
    public static function encode(array $row): string
    {
        $headers = array_keys($row);
        $values = array_map(function ($value) {
            if ($value === null) {
                return '';
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            return (string) $value;
        }, array_values($row));

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            throw new Exception('Failed to open temporary stream for CSV encode');
        }

        fputcsv($fp, $headers);
        fputcsv($fp, $values);
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        if ($csv === false) {
            throw new Exception('Failed to read CSV encode buffer');
        }

        return $csv;
    }

    /**
     * Decode a single-record CSV into an associative array.
     *
     * @return array<string, string>
     */
    public static function decode(string $csv): array
    {
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            throw new Exception('Failed to open temporary stream for CSV decode');
        }

        fwrite($fp, $csv);
        rewind($fp);

        $headers = fgetcsv($fp);
        if ($headers === false || $headers === [null] || count($headers) === 0) {
            fclose($fp);
            throw new Exception('CSV missing header row');
        }

        $values = fgetcsv($fp);
        fclose($fp);

        if ($values === false || $values === [null]) {
            throw new Exception('CSV missing data row');
        }

        // Pad or truncate values to match header count
        $count = count($headers);
        $values = array_pad(array_slice($values, 0, $count), $count, '');

        return array_combine($headers, $values);
    }
}
