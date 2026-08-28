<?php

namespace Erikwang2013\WebmanScout\Tests\Fixtures;

use Erikwang2013\WebmanScout\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PostSoft extends Model
{
    use Searchable, SoftDeletes;

    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
