<?php

namespace JanVince\SmallExtensions\Models;

use Model;

class BlogFields extends Model
{

    protected $primaryKey = 'id';

    public $implement = ['@Winter.Translate.Behaviors.TranslatableModel'];

    public $translatable = [
        'api_code',
        'string',
        'text',
        'repeater',
    ];

    public $table = 'janvince_smallextensions_blogfields';

    public $timestamps = true;

    protected $guarded = ['*'];

    protected $jsonable = ['repeater'];

    public $belongsTo = [
        'post' => ['Winter\Blog\Models\Post', 'key' => 'post_id', 'otherKey' => 'id'],
    ];

}
