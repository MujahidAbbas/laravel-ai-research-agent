<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\ModelRouter;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Enums\Lab;

/**
 * The research agent pinned to Sonnet, with ModelRouter handing the answer
 * step to the cheap model once three tool steps are spent. The model-routing
 * post's per-step example.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
class RoutedResearchAgent extends ResearchAgent implements HasMiddleware
{
    public function middleware(): array
    {
        return [new ModelRouter];
    }
}
