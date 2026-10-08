<?php

declare(strict_types=1);

namespace App\Ai;

use Closure;
use Laravel\Ai\PendingStep;

/**
 * Gather on the agent's own model, finish on a cheap one.
 *
 * Since laravel/ai 1.0, agent middleware wraps each generation step and
 * receives a PendingStep, so the model can change between steps of one run.
 * In the 09-18 runs every expensive Haiku decision was a tool choice (search
 * size, full-text search, which pages to scrape), and the answer step carried
 * 46-60% of a Sonnet run's context and 65-72% of its output. So the tool
 * steps stay on the agent's model, and once they are spent the cheap model
 * writes the answer with tools forbidden: it cannot add to the context it reads.
 *
 * tool_choice none, not withTools([]): the request keeps its tool definitions,
 * which the tool_use blocks already in the history refer to.
 *
 * The cheap model is keyed by provider, because a run that fails over carries
 * the next provider's name on every step. A provider with no entry is left alone.
 */
class ModelRouter
{
    /**
     * @param  array<string, string>  $cheap  The cheap model for each provider.
     * @param  int  $toolSteps  Steps that keep the agent's own model and its tools.
     */
    public function __construct(
        private array $cheap = ['anthropic' => 'claude-haiku-5-5', 'openai' => 'gpt-6-luna'],
        private int $toolSteps = 3,
    ) {}

    public function handle(PendingStep $step, Closure $next)
    {
        if ($step->number < $this->toolSteps || ! isset($this->cheap[$step->provider])) {
            return $next($step);
        }

        return $next($step->withModel($this->cheap[$step->provider])->withToolChoice('none'));
    }
}
