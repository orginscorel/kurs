<?php

use App\Providers\AppServiceProvider;
use App\Providers\DesktopServiceProvider;
use App\Providers\SyncServiceProvider;

return [
    AppServiceProvider::class,
    SyncServiceProvider::class,
    DesktopServiceProvider::class,
];
