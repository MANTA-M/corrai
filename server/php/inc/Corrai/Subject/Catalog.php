<?php

namespace Corrai\Subject;

/**
 * Builds the subject tree (subject, then country, then level) for one locale.
 */
class Catalog
{
    /**
     * @return list<array{subject: string, name: string, countries: list<array{country: string, name: string, levels: list<array{level: string, name: string}>}>}>
     */
    public static function tree(string $locale): array
    {
        $grouped = [];
        foreach (self::classes() as $class) {
            $entry = [
                'subject' => $class::SUBJECT,
                'country' => $class::COUNTRY,
                'level' => $class::LEVEL,
                'names' => $class::NAMES,
            ];
            $grouped[$entry['subject']][] = $entry;
        }

        $subjects = [];
        foreach ($grouped as $subject => $entries) {
            $bare = null;
            $byCountry = [];
            $levels = [];
            foreach ($entries as $entry) {
                if ($entry['country'] === '' && $entry['level'] === '') {
                    $bare = $entry;
                    continue;
                }
                if ($entry['country'] === '') {
                    $levels[] = [
                        'level' => $entry['level'],
                        'name' => self::localizedName($entry, $locale),
                    ];
                    continue;
                }
                $byCountry[$entry['country']][] = $entry;
            }

            $countries = [];
            foreach ($byCountry as $country => $countryEntries) {
                $countryBare = null;
                $countryLevels = [];
                foreach ($countryEntries as $entry) {
                    if ($entry['level'] === '') {
                        $countryBare = $entry;
                    } else {
                        $countryLevels[] = [
                            'level' => $entry['level'],
                            'name' => self::localizedName($entry, $locale),
                        ];
                    }
                }
                $countryNameEntry = $countryBare ?? $countryEntries[0];
                $countries[] = [
                    'country' => $country,
                    'name' => self::localizedName($countryNameEntry, $locale),
                    'levels' => $countryLevels,
                ];
            }

            $subjects[] = [
                'subject' => $subject,
                'name' => $bare !== null ? self::localizedName($bare, $locale) : $subject,
                'countries' => $countries,
                'levels' => $levels,
            ];
        }

        return $subjects;
    }

    /**
     * @param array{subject: string, names: array<string, string>} $entry
     */
    private static function localizedName(array $entry, string $locale): string
    {
        $names = $entry['names'];
        if (isset($names[$locale]) && $names[$locale] !== '') {
            return $names[$locale];
        }
        if (isset($names['en']) && $names['en'] !== '') {
            return $names['en'];
        }
        return $entry['subject'];
    }

    /**
     * Every subject pipeline class, including country and level variants.
     *
     * @return list<class-string>
     */
    public static function classes(): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'Pipeline.php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(__DIR__) + 1, -strlen('/Pipeline.php'));
            $classes[] = 'Corrai\\Subject\\' . str_replace('/', '\\', $relative) . '\\Pipeline';
        }
        sort($classes);
        return $classes;
    }

    /**
     * Most specific pipeline for a subject, country, and level.
     *
     * @return class-string
     */
    public static function pipelineClass(string $subject, string $country, string $level): string
    {
        $countryOnly = null;
        $bare = null;
        foreach (self::classes() as $class) {
            if ($class::SUBJECT !== $subject) {
                continue;
            }
            $entryCountry = $class::COUNTRY;
            $entryLevel = $class::LEVEL;
            if ($entryCountry === $country && $entryLevel === $level) {
                return $class;
            }
            if ($entryCountry === $country && $entryLevel === '') {
                $countryOnly = $class;
            }
            if ($entryCountry === '' && $entryLevel === '') {
                $bare = $class;
            }
        }
        return $countryOnly ?? $bare ?? \Corrai\Subject\Other\Pipeline::class;
    }
}
