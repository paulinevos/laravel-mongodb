<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use MongoDB\BSON\Binary;
use Override;
use Stringable;

use function array_filter;
use function array_key_last;
use function array_merge;
use function array_values;
use function bin2hex;
use function get_debug_type;
use function is_scalar;
use function sprintf;

/**
 * MongoDB has neither joins nor qualified column names, so the intermediate keys
 * of a through relation are read with one query against the through collection,
 * then the related documents are constrained with a "whereIn" on those keys.
 *
 * @internal
 */
trait ResolvesThroughKeys
{
    /** @var array<string, string> */
    private array $farParentKeyByThroughKey = [];

    /** @var list<mixed>|null */
    private ?array $resolvedFarParentKeys = null;

    private int|string|null $throughKeyWhereIndex = null;

    private bool $keepTrashedParents = false;

    /** @inheritdoc */
    #[Override]
    public function addConstraints()
    {
        if (! static::$constraints) {
            return;
        }

        $this->constrainByFarParentKeys([$this->farParent->getAttribute($this->localKey)]);
    }

    /** @inheritdoc */
    #[Override]
    public function addEagerConstraints(array $models)
    {
        $this->constrainByFarParentKeys($this->getKeys($models, $this->localKey));
    }

    /** @inheritdoc */
    #[Override]
    public function withTrashedParents()
    {
        $this->keepTrashedParents = true;

        if ($this->resolvedFarParentKeys !== null) {
            $this->constrainByFarParentKeys($this->resolvedFarParentKeys);
        }

        return $this;
    }

    /** @inheritdoc */
    #[Override]
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        return $query;
    }

    /**
     * The key matching the related documents to their far parent must be read,
     * as the "laravel_through_key" alias of Eloquent requires a join.
     *
     * @inheritdoc
     */
    #[Override]
    protected function shouldSelect(array $columns = ['*'])
    {
        if ($columns === ['*']) {
            return $columns;
        }

        return array_merge($columns, [$this->secondKey]);
    }

    /**
     * The far parent key of every document matched by the given related query,
     * repeated once per document so that occurrences can be counted.
     *
     * @param Builder $relatedQuery
     *
     * @return Collection
     */
    public function pluckFarParentKeys(Builder $relatedQuery)
    {
        $throughKeys = $relatedQuery->pluck($this->secondKey);

        $farParentKeys = $this->readFarParentKeys($this->secondLocalKey, $throughKeys->all());

        return $throughKeys
            ->map(fn (mixed $throughKey): ?string => self::lookUpFarParentKey($farParentKeys, $throughKey))
            ->reject(fn (?string $farParentKey): bool => $farParentKey === null)
            ->values();
    }

    /**
     * @param Model $farParent
     *
     * @return string|null
     */
    public function getFarParentDictionaryKey(Model $farParent)
    {
        return self::dictionaryKey($farParent->getAttribute($this->localKey));
    }

    /** @inheritdoc */
    #[Override]
    protected function buildDictionary(EloquentCollection $results)
    {
        $dictionary = [];

        foreach ($results as $result) {
            $farParentKey = self::lookUpFarParentKey(
                $this->farParentKeyByThroughKey,
                $result->getAttribute($this->secondKey),
            );

            if ($farParentKey === null) {
                continue;
            }

            $dictionary[$farParentKey][] = $result;
        }

        return $dictionary;
    }

    /** @param list<mixed> $farParentKeys */
    private function constrainByFarParentKeys(array $farParentKeys): void
    {
        $this->resolvedFarParentKeys = $farParentKeys;

        $throughParents = $this->readThroughParents($this->firstKey, $farParentKeys);

        $this->farParentKeyByThroughKey = $this->indexFarParentKeys($throughParents);

        $this->constrainRelatedQuery(
            $throughParents->pluck($this->secondLocalKey)->all(),
        );
    }

    /** @param list<mixed> $throughKeys */
    private function constrainRelatedQuery(array $throughKeys): void
    {
        $query = $this->query->getQuery();

        if ($this->throughKeyWhereIndex !== null) {
            $query->wheres[$this->throughKeyWhereIndex]['values'] = $throughKeys;

            return;
        }

        $this->query->whereIn($this->secondKey, $throughKeys);

        $this->throughKeyWhereIndex = array_key_last($query->wheres);
    }

    /**
     * @param list<mixed> $values
     *
     * @return array<string, string>
     */
    private function readFarParentKeys(string $column, array $values): array
    {
        return $this->indexFarParentKeys($this->readThroughParents($column, $values));
    }

    /**
     * @param list<mixed> $values
     *
     * @return EloquentCollection
     */
    private function readThroughParents(string $column, array $values)
    {
        $values = array_values(array_filter($values, static fn (mixed $value): bool => $value !== null));

        if ($values === []) {
            return $this->throughParent->newCollection();
        }

        return $this->newThroughQuery()->whereIn($column, $values)->get();
    }

    /** @return Builder */
    private function newThroughQuery()
    {
        $query = $this->throughParent->newQuery();

        if ($this->keepTrashedParents) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->select([$this->secondLocalKey, $this->firstKey]);
    }

    /** @return array<string, string> */
    private function indexFarParentKeys(EloquentCollection $throughParents): array
    {
        $keys = [];

        foreach ($throughParents as $throughParent) {
            $keys += $this->farParentKeyPair($throughParent);
        }

        return $keys;
    }

    /** @return array<string, string> */
    private function farParentKeyPair(Model $throughParent): array
    {
        $throughKey = self::dictionaryKey($throughParent->getAttribute($this->secondLocalKey));
        $farParentKey = self::dictionaryKey($throughParent->getAttribute($this->firstKey));

        if ($throughKey === null || $farParentKey === null) {
            return [];
        }

        return [$throughKey => $farParentKey];
    }

    /** @param array<string, string> $farParentKeys */
    private static function lookUpFarParentKey(array $farParentKeys, mixed $throughKey): ?string
    {
        $key = self::dictionaryKey($throughKey);

        if ($key === null) {
            return null;
        }

        return $farParentKeys[$key] ?? null;
    }

    /** Document keys are compared as strings, as ObjectId instances are not identical. */
    private static function dictionaryKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Binary) {
            return bin2hex($value->getData());
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf(
            'The relation key of type "%s" cannot be used to match the documents of a through relation.',
            get_debug_type($value),
        ));
    }
}
