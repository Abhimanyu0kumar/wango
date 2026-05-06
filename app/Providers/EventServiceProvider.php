<?php

namespace App\Providers;

use App\Events\GameStatusChanged;
use App\Listeners\HandleGameActivated;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        GameStatusChanged::class => [
            HandleGameActivated::class,
        ],
    ];

    public function boot(): void
    {
        //
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
