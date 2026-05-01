<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class ScheduleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // Process game rounds every minute (Laravel scheduler runs every minute)
            $schedule->command('game:process-rounds')->everyMinute();
            
            // Clean up old idempotency keys daily
            $schedule->command('model:prune', [
                '--model' => [\App\Models\IdempotencyKey::class],
            ])->daily();
        });
    }
}
