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
        foreach (AssessmentFactory::classes() as $class) {
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
            $entry = [
                'subject' => (string) ($defaults['subject'] ?? ''),
                'country' => self::blank($defaults['country'] ?? null),
                'level' => self::blank($defaults['level'] ?? null),
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
                $countryLevels = [];
                foreach ($countryEntries as $entry) {
                    if ($entry['level'] === '') {
                        continue;
                    }
                    $countryLevels[] = [
                        'level' => $entry['level'],
                        'name' => self::localizedName($entry, $locale),
                    ];
                }
                usort(
                    $countryLevels,
                    static fn (array $a, array $b): int => strcmp($a['level'], $b['level'])
                );
                $countries[] = [
                    'country' => $country,
                    'name' => self::countryName($country, $locale),
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
     * Localized country name for an ISO 3166-1 alpha-2 code.
     */
    private static function countryName(string $country, string $locale): string
    {
        $region = strtoupper($country);
        if ($region === '' || !class_exists(\Locale::class)) {
            return $country;
        }
        $name = \Locale::getDisplayRegion('-' . $region, $locale);
        if (!is_string($name) || $name === '' || strcasecmp($name, $region) === 0) {
            $name = \Locale::getDisplayRegion('-' . $region, 'en');
        }
        if (!is_string($name) || $name === '' || strcasecmp($name, $region) === 0) {
            return $country;
        }
        return $name;
    }

    /**
     * Every subject task class, including country and level variants.
     *
     * @return list<class-string>
     */
    public static function classes(): array
    {
        $classes = [];
        foreach (AssessmentFactory::classes() as $assessmentClass) {
            $prefix = substr($assessmentClass, 0, -strlen('Assessment'));
            foreach ([
                'Task1Transcribing',
                'Task1Correcting',
                'Task2Correcting',
                'Task2Annotating',
                'Task3Annotating',
                'Task3Rendering',
            ] as $suffix) {
                $class = $prefix . $suffix;
                if (self::taskExists($class)) {
                    $classes[] = $class;
                }
            }
        }
        $classes = array_values(array_unique($classes));
        sort($classes);
        return $classes;
    }

    /**
     * Entry task for a subject, country, and level.
     *
     * The subject tree lives on each package Assessment. The entry task is the
     * first task class in that same package.
     *
     * @return class-string
     */
    public static function pipelineClass(string $subject, string $country, string $level): string
    {
        $assessmentClass = AssessmentFactory::assessmentClass($subject, $country, $level);
        $prefix = $assessmentClass === \Corrai\Model\Assessment::class
            ? 'Corrai\\Subject\\Other\\'
            : substr($assessmentClass, 0, -strlen('Assessment'));
        foreach (['Task1Transcribing', 'Task1Correcting'] as $suffix) {
            $class = $prefix . $suffix;
            if (self::taskExists($class)) {
                return $class;
            }
        }
        return \Corrai\Subject\Other\Task1Transcribing::class;
    }

    /**
     * True when the task class has a PHP file, including TaskNName.php for NameTask.
     */
    private static function taskExists(string $class): bool
    {
        if (!str_starts_with($class, 'Corrai\\')) {
            return false;
        }
        $relative = substr($class, strlen('Corrai\\'));
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            return true;
        }
        $short = basename(str_replace('\\', '/', $relative));
        if (!str_ends_with($short, 'Task')) {
            return false;
        }
        $stem = substr($short, 0, -strlen('Task'));
        $matches = glob(dirname($file) . '/Task*' . $stem . '.php');
        return is_array($matches) && $matches !== [];
    }

    private static function blank(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return trim($value);
    }
}
