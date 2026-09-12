<?php

namespace App\Providers;

use App\Ai\StepUsageRecorder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton(StepUsageRecorder::class);

        Event::listen(StartingStep::class, [StepUsageRecorder::class, 'starting']);
        Event::listen(StepCompleted::class, [StepUsageRecorder::class, 'completed']);
    }
}
