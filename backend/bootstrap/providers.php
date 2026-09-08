<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    DomainServiceProvider::class,
    RateLimitServiceProvider::class,
];
