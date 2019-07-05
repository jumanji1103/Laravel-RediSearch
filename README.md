# Laravel-RediSearch

An experimental [Laravel Scout](https://laravel.com/docs/5.6/scout) driver for [RediSearch](http://redisearch.io) that uses [RediSearch-PHP](https://github.com/ethanhann/redisearch-php) under the hood.

Documentation: http://www.ethanhann.com/redisearch-php/laravel-support/


## Customization
I've made some modification in order to configure Redis connection, update index on model changing and exploit weights and relationships in building index.

### Redis connection
It is possible to configure which redis connection to use, by default it uses the `default` connection already set in Laravel `config/database` file.  
However, normally the `default` connection is used also to maintain laravel cache and is wipped out every time we run the `cache:clear` command.  
**Keep in mind that redissearch accept only redis connection whose DB is 0.**  
For this reason there are two solutions:
 - maintain two different containers for redis and redissearch  
 - use a different DB for laravel cache and use the default connection for redissearch.  

To indicate which connection to use, set the `REDISEARCH_CONNECTION` variable in the `.env` file.

### Exploit weight and relationships
Normally, scout `toSearchableArray()` method will be implemented simply by indicating the array transformation of the model.  
To be able to exploit relationships and give a weight to each field we want to index, this particular structure can be used:
```
// example: Posts have User as author and Comments
[
    "structure" => [
        "relationships" => [
            "author",
            "comments",
        ],
    ],
    "searchable" => [
        "id" => 132, // optional
        "title" => [
            "value" => "Lorem ipsum",
            "weight" => 2.0,
        ],
        "author.0.name" => [
            "value" => "John Doe",
            "weight" => 1.0,
        ],
        "comments.0.body" => [
            "value" => "Whoa! Great post!",
            "weight" => 0.8,
        ],
        ...
    ]
];
```
The `structure` key includes the relationships available. Indicate the same name you would use to build a query like `Post::with(['author', 'comments'])->get();`.  
If not specified, `weight` is 1.0, otherwise, it will be used the value in the array.  
Both methods, the normal Scout implementation and this one, works fine.
