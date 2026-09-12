<?php

namespace App\Providers;

use App\Ai\StepUsageRecorder;
use App\Ai\TokenBudget;
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
        // Singletons, so every StartingStep/StepCompleted event of a run lands
        // on the same instance and the per-invocation rows accumulate.
        $this->app->singleton(StepUsageRecorder::class);
        $this->app->singleton(TokenBudget::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(StartingStep::class, [StepUsageRecorder::class, 'starting']);
        Event::listen(StepCompleted::class, [StepUsageRecorder::class, 'completed']);

        // The budget guard runs after the recorder so a refused run still has its rows.
        Event::listen(StartingStep::class, [TokenBudget::class, 'starting']);
        Event::listen(StepCompleted::class, [TokenBudget::class, 'completed']);
    }
}
