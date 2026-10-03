<?php

namespace App\Data;

use App\Helpers\Text;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Spatie\LaravelData\Data;

class BaseData extends Data
{
    /**
     * This act as global wrapping & disables nested collection wrapping.
     *
     * Nested collection wrapping just doesn't seem to make sense given we are not following JSON:API spec in the first place.
     *
     *
     * @see https://github.com/spatie/laravel-data/discussions/737#discussioncomment-12327849
     * @see https://spatie.be/docs/laravel-data/v4/as-a-resource/wrapping#content-nested-wrapping
     */
    public function defaultWrap(): string
    {
        return 'data';
    }

    /**
     * @return string[]
     */
    public static function relations(): array
    {
        return [];
    }

    /**
     * @return array<int, string | Expression>
     */
    public static function additionalSelects(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public static function existRelations(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public static function countRelations(): array
    {
        return [];
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function sumRelations(): array
    {
        return [];
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function avgRelations(): array
    {
        return [];
    }

    /**
     * Prepare eloquent query before processing DTO. It should load all the necessary data / properties.
     */
    public static function prepareQuery(Builder|Relation $query): Builder|Relation
    {
        $query = $query
            ->with(static::relations())
            ->withCount(static::countRelations())
            ->withExists(static::existRelations());

        if (count(static::additionalSelects()) > 0) {
            $columns = match (true) {
                $query instanceof EloquentBuilder => $query->getQuery()->columns,
                $query instanceof QueryBuilder => $query->columns,
                default => null,
            };

            // Workaround for addSelect overriding 'select *'
            // See: https://github.com/laravel/framework/discussions/36113#discussioncomment-336964
            if ($columns === null) {
                $query = $query->select($query->qualifyColumn('*'));
            }

            foreach (static::additionalSelects() as $column) {
                $query = $query->addSelect($column);
            }
        }

        foreach (static::sumRelations() as $relation) {
            $query = $query->withSum(...$relation);
        }

        foreach (static::avgRelations() as $relation) {
            $query = $query->withAvg(...$relation);
        }

        return $query;
    }

    /**
     * Load required relations into model.
     */
    public static function loadRelations(Model $model): Model
    {
        if (count(static::relations())) {
            $model = $model->loadMissing(static::relations());
        }

        if (count(static::countRelations())) {
            $model = $model->loadCount(static::countRelations());
        }

        if (count(static::existRelations())) {
            $model = $model->loadExists(static::existRelations());
        }

        foreach (self::sumRelations() as $relation) {
            $model->loadSum(...$relation);
        }

        foreach (self::avgRelations() as $relation) {
            $model->loadAvg(...$relation);
        }

        return $model;
    }

    /**
     * Helper for prefixing nested relations based on parent relation name
     *
     * @param  string[]  $relations
     * @return string[]
     */
    public static function relationsFromNested(string $name, array $relations): array
    {
        return [
            rtrim($name, '.'),
            ...Text::arrayPrefix($name, $relations),
        ];
    }
}
