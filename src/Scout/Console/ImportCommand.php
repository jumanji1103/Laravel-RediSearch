<?php

namespace Ehann\LaravelRediSearch\Scout\Console;

use DB;
use Ehann\RediSearch\Fields\FieldFactory;
use Ehann\RediSearch\Index;
use Ehann\RedisRaw\RedisRawClientInterface;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'ehann:redisearch:import 
                            {model : The model class to import.} 
                            {--recreate-index : Drop the index before importing.}
                            {--no-id : Do not select by "id" primary key.}
                            {--delete : Drop the index.}
                            ';
    protected $description = 'Import models into index';

    public function handle(RedisRawClientInterface $redisClient)
    {
        $class = $this->argument('model');
        $model = new $class();
        $index = new Index($redisClient, $model->searchableAs());

        if($this->option('delete')) {
            $index->drop();
            $this->info('Dropped index.');
            return;
        }

        $searchables = $model->toSearchableArray();

        $fields = (isset($searchables['structure']))? $searchables['structure'] : array_keys($searchables);
        $relationships = (isset($fields['relationships']))? $fields['relationships'] : null;
        unset($fields['relationships']);

        if (!$this->option('no-id')) {
            $fields[] = $model->getKeyName();
            $query = implode(', ', array_unique($fields));
        }

        if ($this->option('no-id') || $query === '') {
            $query = '*';
        }

        $records = ($relationships != null)? $model::with($relationships)->get() : $model::all();

        $records = $records->map(function ($item, $key) {
            $searchable = $item->toSearchableArray();
            if(isset($searchable['searchable'])) {
                $searchable = $searchable['searchable'];
            }
            return (object) array_merge(['id' => $item->id], $searchable);
        });

        // Define Schema
        $records->each(function ($item) use ($index, $model) {
            foreach ($item as $name => $attributes) {
                if ($name !== $model->getKeyName()) {
                    if(is_array($attributes)) $value = $attributes['value'];
                    $value = $value ?? '';
                    $index->$name = FieldFactory::make($name, '');
                }
            }
        });

        if ($records->isEmpty()) {
            $this->warn('There are no models to import.');
        }

        if ($this->option('recreate-index')) {
            $index->drop();
        }

        if (!$index->create()) {
            $this->warn('The index already exists. Use --recreate-index to recreate the index before importing.');
        }


        foreach ($records as $record) {
            $document = $index->makeDocument();
            if (property_exists($record, $model->getKeyName())) {
                $document->setId($record->{$model->getKeyName()});
            }
            $fields = array_keys((array) $record);
            foreach ($fields as $key) {
                if($key != $model->getKeyName()) {
                    $value = (is_array($record->$key))? $record->$key['value'] : $record->$key;
                    $value = $value ?? '';
                    $document->$key = FieldFactory::make($key, $value);
                    if(isset($record->$key['weight'])){
                        $document->$key->setWeight($record->$key['weight']);
                    }
                }
            }
            $index->add($document);
        }

        // $records
        //     ->each(function ($item) use ($index, $model) {
        //         $document = $index->makeDocument(
        //             property_exists($item, $model->getKeyName()) ? $item->{$model->getKeyName()} : null
        //         );
        //         foreach ((array)$item as $name => $value) {
        //             if ($name !== $model->getKeyName()) {
        //                 $value = $value ?? '';
        //                 $document->$name = FieldFactory::make($name, $value);
        //             }
        //         }
        //         dd($document);
        //         $index->add($document);
        //     });

        $this->info('All [' . $class . '] records have been imported.');
    }
}
