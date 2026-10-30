<?php

namespace Observera\Laravel\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

class AuthUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
