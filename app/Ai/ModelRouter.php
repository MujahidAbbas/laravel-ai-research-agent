<?php

declare(strict_types=1);

namespace App\Ai;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Route the model per prompt instead of per class.
 *
 * The attributes bind one model to the class for the life of the process.
 * Middleware is the only place that sees the actual prompt before the request
 * is built, so it is the only place a routing decision can look at the work.
 *
 * Two things to know. AgentPrompt::$model is readonly and revise() copies the
 * old model into the new instance, so a router builds the prompt by hand. And
 * the provider is NOT taken from the prompt - the destination closure passes
 * the provider whose prompt() was entered - so changing $prompt->provider here
 * changes what your logs say, not where the request goes. Route the model only.
 */
class ModelRouter
{
    /**
     * @param  string  $cheap  Model for prompts under the threshold.
     * @param  string  $smart  Model for everything else.
     * @param  int  $threshold  Prompt length, in characters, that buys the smart model.
     */
    public function __construct(
        private string $cheap = 'claude-haiku-4-5-20251001',
        private string $smart = 'claude-sonnet-4-6',
        private int $threshold = 280,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse
    {
        return $next($this->withModel(
            $prompt,
            strlen($prompt->prompt) < $this->threshold ? $this->cheap : $this->smart,
        ));
    }

    /**
     * Rebuild the prompt on a different model. There is no withModel() helper
     * in the SDK, and revise() will hand back the model you are trying to change.
     *
     * Carry every remaining argument. The constructor defaults them all to null,
     * so a short version of this method compiles, runs, and quietly drops the
     * tool-approval decisions and the parent invocation ids - which breaks
     * approval resumes and orphans sub-agent traces, on the routed path only.
     */
    private function withModel(AgentPrompt $prompt, string $model): AgentPrompt
    {
        return new AgentPrompt(
            $prompt->agent,
            $prompt->prompt,
            $prompt->attachments,
            $prompt->provider,
            $model,
            $prompt->timeout,
            $prompt->invocationId,
            $prompt->approvalDecisions,
            $prompt->parentInvocationId,
            $prompt->parentToolInvocationId,
            $prompt->isFinalAttempt(),
        );
    }
}
