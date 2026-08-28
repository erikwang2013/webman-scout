<?php

namespace Erikwang2013\WebmanScout\Tests\Fixtures;

use Erikwang2013\WebmanScout\Attributes\SearchUsingFullText;
use Erikwang2013\WebmanScout\Attributes\SearchUsingPrefix;
use Erikwang2013\WebmanScout\Searchable;
use Illuminate\Database\Eloquent\Model;

class PostFullText extends Model
{
    use Searchable;

    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    #[SearchUsingFullText(['body'], ['language' => 'simple'])]
    #[SearchUsingPrefix(['title'])]
    final public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
