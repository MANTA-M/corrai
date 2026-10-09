<?php

namespace Corrai\Model;

use Corrai\Utils\Http\WSException;
use Corrai\Utils\Store\ObjectStore;
use Corrai\Utils\Store\StoreConflictException;

/**
 * Raw attributes.json of an assessment, a student, or an input file.
 */
final class AttributeDocument
{
    /**
     * @return array{attributes: array<string, mixed>, etag: string|null}
     */
    public static function read(BaseAssessment $assessment, ?string $studentId, ?string $fileId): array
    {
        $loaded = ObjectStore::getInstance()->getJson(self::key($assessment, $studentId, $fileId));
        return [
            'attributes' => $loaded['data'],
            'etag' => $loaded['etag'],
        ];
    }

    /**
     * @param array<mixed> $attributes
     */
    public static function write(
        BaseAssessment $assessment,
        ?string $studentId,
        ?string $fileId,
        array $attributes,
        ?string $etag
    ): ?string {
        if (array_is_list($attributes) && $attributes !== []) {
            throw new WSException('Attributes must be a JSON object', 400);
        }
        $match = $etag !== null && $etag !== '' ? $etag : null;
        try {
            return ObjectStore::getInstance()->putJson(
                self::key($assessment, $studentId, $fileId),
                $attributes,
                $match
            );
        } catch (StoreConflictException $e) {
            throw new WSException('Attributes were modified', 409, $e);
        }
    }

    private static function key(BaseAssessment $assessment, ?string $studentId, ?string $fileId): string
    {
        $studentId = self::present($studentId);
        $fileId = self::present($fileId);
        if ($studentId !== null && $fileId !== null) {
            throw new WSException('Provide student or file, not both', 400);
        }
        if ($fileId !== null) {
            $file = $assessment->getFile($fileId);
            if (!$file instanceof InputFile) {
                throw new WSException('File has no attributes', 400);
            }
            return $file->attrKey();
        }
        if ($studentId !== null) {
            return $assessment->getStudent($studentId)->attrKey();
        }
        if ($assessment->id === null || $assessment->id === '') {
            throw new WSException('Assessment has no id', 400);
        }
        return ObjectStore::assessmentAttrKey(
            $assessment->school_id,
            $assessment->user_id,
            $assessment->id
        );
    }

    private static function present(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
