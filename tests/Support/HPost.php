<?php

namespace Observera\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class HPost extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];
}
