<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Relations;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EloquentHasOneThrough;
use Override;

use function reset;

/**
 * @template TRelatedModel of Model
 * @template TIntermediateModel of Model
 * @template TDeclaringModel of Model
 * @extends EloquentHasOneThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
 */
class HasOneThrough extends EloquentHasOneThrough implements ThroughRelation
{
    use ResolvesThroughKeys;

    /** @inheritdoc */
    #[Override]
    public function match(array $models, EloquentCollection $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $this->getFarParentDictionaryKey($model);

            if ($key === null || ! isset($dictionary[$key])) {
                continue;
            }

            $model->setRelation($relation, reset($dictionary[$key]));
        }

        return $models;
    }

    /**
     * Get the name of the "where in" method for eager loading.
     *
     * @inheritdoc
     */
    #[Override]
    protected function whereInMethod(Model $model, $key)
    {
        return 'whereIn';
    }
}
