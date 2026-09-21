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

    /**
     * Encode multiple associative rows as CSV (header row + data rows).
     * Headers are taken from the first row; later rows are aligned to those keys.
     *
     * @param array<int, array<string, scalar|null>> $rows
     */
    public static function encodeRows(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $headers = array_keys($rows[0]);
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            throw new Exception('Failed to open temporary stream for CSV encode');
        }

        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                if ($value === null) {
                    $values[] = '';
                } elseif (is_bool($value)) {
                    $values[] = $value ? '1' : '0';
                } else {
                    $values[] = (string) $value;
                }
            }
            fputcsv($fp, $values);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        if ($csv === false) {
            throw new Exception('Failed to read CSV encode buffer');
        }

        return $csv;
    }

    /**
     * Decode a multi-row CSV into a list of associative arrays.
     *
     * @return array<int, array<string, string>>
     */
    public static function decodeRows(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

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

        $rows = [];
        $count = count($headers);
        while (($values = fgetcsv($fp)) !== false) {
            if ($values === [null]) {
                continue;
            }
            $values = array_pad(array_slice($values, 0, $count), $count, '');
            $combined = array_combine($headers, $values);
            if ($combined !== false) {
                $rows[] = $combined;
            }
        }
        fclose($fp);

        return $rows;
    }
}
