<?php

namespace Ehann\LaravelRediSearch\Scout\Engines;

use Ehann\RediSearch\Index;
use Ehann\RedisRaw\RedisRawClientInterface;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Ehann\RediSearch\Fields\FieldFactory;
use Carbon\Carbon;
use Ehann\RediSearch\Language;

class RediSearchEngine extends Engine
{
    /**
     * @var RedisRawClientInterface
     */
    private $redisRawClient;

    /**
     * RediSearchEngine constructor.
     * @param RedisRawClientInterface $redisRawClient
     */
    public function __construct(RedisRawClientInterface $redisRawClient)
    {
        $this->redisRawClient = $redisRawClient;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection $models
     * @return void
     */
    public function update($models)
    {
        $index = new Index($this->redisRawClient, $models->first()->searchableAs());

        foreach ($models as $model) {
            $document = $index->makeDocument();
            $searchables = $model->toSearchableArray();
            $searchables = (isset($searchables['searchable']))? $searchables['searchable'] : $searchables;

            foreach ($searchables as $key => $value) {
                if($key != $model->getKeyName()) {
                    $value = (is_array($value))? $value['value'] : $value;
                    $value = $value ?? '';
                    $document->$key = FieldFactory::make($key, $value);
                    if(isset($value['weight'])){
                        $document->$key->setWeight($value['weight']);
                    }
                }
            }
            $id = (isset($searchables['id']))? $searchables['id'] : $model->id;
            $document->setId($id);

            $index->replace($document);
        }

        // $models
        //     ->map(function ($model) {
        //         $array = $model->toSearchableArray();
        //         if (empty($array)) {
        //             return;
        //         }
        //         return array_merge(['id' => $model->getKey()], $array);
        //     })
        //     ->filter()
        //     ->values()
        //     ->each(function ($item) use ($index) {
        //         $index->add($item);
        //     });
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection $models
     * @return void
     */
    public function delete($models)
    {
        $index = new Index($this->redisRawClient, $models->first()->searchableAs());
        $models
            ->map(function ($model) {
                return $model->getKey();
            })
            ->values()
            ->each(function ($key) use ($index) {
                $index->delete($key);
            });
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return (new Index($this->redisRawClient, $builder->index ?? $builder->model->searchableAs()))
            ->language(Language::ITALIAN)
            ->search($builder->query);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder $builder
     * @param  int $perPage
     * @param  int $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {

        return collect((new Index($this->redisRawClient, $builder->index ?? $builder->model->searchableAs()))
            ->limit($page, $perPage)
            ->search($builder->query));
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results->getDocuments())->pluck('id')->values();
    }

    public function map(Builder $builder, $results, $model)
    {
        $count = $results->getCount();
        if ($count === 0) {
            return Collection::make();
        }

        $documents = $results->getDocuments();
        $keys = collect($documents)
            ->pluck('id')
            ->values()
            ->all();
        $models = $model
            ->whereIn($model->getQualifiedKeyName(), $keys)
            ->get()
            ->keyBy($model->getKeyName());

        return Collection::make($documents)
            ->map(function ($hit) use ($model, $models) {
                $key = $hit->id;
                if (isset($models[$key])) {
                    return $models[$key];
                }
            })->filter();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results->first();
    }

    public function flush($model)
    {
        $index = new Index($this->redisRawClient, $model->searchableAs());
        $index->drop();
    }
}
