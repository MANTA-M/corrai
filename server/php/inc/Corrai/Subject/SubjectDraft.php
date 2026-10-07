<?php

namespace Corrai\Subject;

/**
 * Turns a model JSON answer into assessment attributes that match the subject tree.
 */
class SubjectDraft
{
    /**
     * @param array<string, mixed> $raw
     * @return array{name: string, subject: string, level: ?string, country: ?string, date: string}
     */
    public static function normalize(array $raw, string $userCountry, string $filename): array
    {
        $tree = Catalog::tree('en');
        $bySubject = [];
        foreach ($tree as $node) {
            $bySubject[strtolower($node['subject'])] = $node;
        }

        $subjectCode = trim((string) ($raw['subject'] ?? ''));
        $node = $bySubject[strtolower($subjectCode)] ?? $bySubject['other'] ?? null;
        $subject = is_array($node) ? (string) $node['subject'] : 'Other';

        $levelRaw = trim((string) ($raw['level'] ?? ''));
        $level = null;
        $detectedCountry = is_array($node)
            ? self::matchCountry($node, (string) ($raw['country'] ?? ''))
            : null;
        $country = $detectedCountry ?? self::countryOrNull($userCountry);
        if (is_array($node) && $levelRaw !== '') {
            $matched = self::matchLevel($node, $levelRaw, $detectedCountry ?? $userCountry);
            if ($matched !== null) {
                $level = $matched['level'];
                if ($matched['country'] !== null) {
                    $country = $matched['country'];
                }
            } elseif (self::isLevelToken($levelRaw)) {
                $level = $levelRaw;
            }
        }

        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $name = pathinfo($filename, PATHINFO_FILENAME);
        }
        if (mb_strlen($name) > 200) {
            $name = mb_substr($name, 0, 200);
        }

        return [
            'name' => $name,
            'subject' => $subject,
            'level' => $level,
            'country' => $country,
            'date' => self::dateOrEmpty((string) ($raw['date'] ?? '')),
        ];
    }

    /**
     * @param array{subject: string, levels: list<array{level: string}>, countries: list<array{country: string, levels: list<array{level: string}>}>} $node
     * @return array{level: string, country: ?string}|null
     */
    private static function matchLevel(array $node, string $levelRaw, string $userCountry): ?array
    {
        $wanted = strtolower($levelRaw);
        $matches = [];
        foreach ($node['levels'] as $entry) {
            if (strtolower((string) $entry['level']) === $wanted) {
                $matches[] = ['level' => (string) $entry['level'], 'country' => null];
            }
        }
        foreach ($node['countries'] as $countryNode) {
            foreach ($countryNode['levels'] as $entry) {
                if (strtolower((string) $entry['level']) === $wanted) {
                    $matches[] = [
                        'level' => (string) $entry['level'],
                        'country' => (string) $countryNode['country'],
                    ];
                }
            }
        }
        if ($matches === []) {
            return null;
        }
        foreach ($matches as $match) {
            if ($match['country'] !== null && strcasecmp($match['country'], $userCountry) === 0) {
                return $match;
            }
        }
        return $matches[0];
    }

    /**
     * Country code from the page when it names a country of this subject.
     *
     * @param array{countries: list<array{country: string, name?: string}>} $node
     */
    private static function matchCountry(array $node, string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        foreach ($node['countries'] as $countryNode) {
            $code = (string) $countryNode['country'];
            $name = (string) ($countryNode['name'] ?? '');
            if (strcasecmp($code, $raw) === 0 || ($name !== '' && strcasecmp($name, $raw) === 0)) {
                return $code;
            }
        }
        return null;
    }

    private static function isLevelToken(string $level): bool
    {
        return (bool) preg_match('/^[\p{L}\p{N} ._-]{1,40}$/u', $level);
    }

    private static function countryOrNull(string $country): ?string
    {
        $country = strtolower(trim($country));
        return $country === '' ? null : $country;
    }

    private static function dateOrEmpty(string $date): string
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }
        $parts = explode('-', $date);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return '';
        }
        return $date;
    }
}
