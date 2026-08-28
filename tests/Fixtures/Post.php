<?php

namespace Erikwang2013\WebmanScout\Tests\Fixtures;

use Erikwang2013\WebmanScout\Searchable;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use Searchable;

    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
        ];
    }
}
