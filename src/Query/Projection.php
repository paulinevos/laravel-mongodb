<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Query;

use LogicException;

use function array_is_list;
use function array_key_first;
use function count;
use function is_array;
use function is_int;
use function sprintf;

/** @internal */
final class Projection
{
    /**
     * @param array<string, mixed> $projection
     *
     * @return array<string, mixed>
     */
    public static function translate(array $projection): array
    {
        $translated = [];

        foreach ($projection as $field => $specification) {
            $translated[$field] = self::translateField($field, $specification);
        }

        return $translated;
    }

    private static function translateField(string $field, mixed $specification): mixed
    {
        if (! is_array($specification)) {
            return $specification;
        }

        if (isset($specification['$slice'])) {
            return ['$slice' => self::sliceArguments($field, $specification['$slice'])];
        }

        throw new LogicException(sprintf(
            'The projection of "%s" uses the operator "%s", which cannot be compiled into an aggregation $project stage. Project the field without it.',
            $field,
            (string) array_key_first($specification),
        ));
    }

    /** @return list<mixed> */
    private static function sliceArguments(string $field, mixed $arguments): array
    {
        if (is_int($arguments)) {
            return ['$' . $field, $arguments];
        }

        if (is_array($arguments) && array_is_list($arguments) && count($arguments) === 2) {
            return ['$' . $field, $arguments[0], $arguments[1]];
        }

        throw new LogicException(sprintf(
            'The projection of "%s" slices with arguments that cannot be compiled into an aggregation $project stage. Slice with a count, or with a skip and a count.',
            $field,
        ));
    }
}
