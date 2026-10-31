<?php

namespace Observera\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/** The host app's own provider, registered AFTER the SDK: it wants lazy loading to throw. */
class AppLikeProvider extends ServiceProvider
{
    public function boot(): void
    {
        Model::preventLazyLoading();
    }
}
