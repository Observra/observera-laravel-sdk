<?php

namespace Observera\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HUser extends Model
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /** @return HasMany<HPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(HPost::class, 'user_id');
    }
}
