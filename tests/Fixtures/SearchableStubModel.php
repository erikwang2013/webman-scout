<?php

namespace Erikwang2013\WebmanScout\Tests\Fixtures;

use Erikwang2013\WebmanScout\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SearchableStubModel extends Model
{
    use Searchable;
    use SoftDeletes;

    protected $table = 'scout_stub_models';

    protected $guarded = [];

    public $timestamps = false;
}
