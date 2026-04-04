<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/*
 * Horizon is optional at boot: if `composer install` has not run or laravel/horizon
 * failed to install, referencing HorizonServiceProvider would fatal (missing parent class).
 * Register it only when the package is present. Queues still need Horizon installed
 * for `php artisan horizon` and the /horizon dashboard.
 */
$providers = [AppServiceProvider::class];

if (class_exists(HorizonApplicationServiceProvider::class)) {
    $providers[] = HorizonServiceProvider::class;
}

return $providers;
