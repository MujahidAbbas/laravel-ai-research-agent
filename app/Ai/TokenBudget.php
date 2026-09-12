<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Exceptions\TokenBudgetExceeded;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;

/**
 * A run-level token ceiling the SDK does not have.
 *
 * MaxSteps bounds how many steps a run may take. It says nothing about how
 * much context each step carries, and the context is what compounds. This
 * listener enforces a ceiling on the tokens a single invocation may send:
 *
 *  - StartingStep:  estimate the context about to be sent and refuse the
 *                   call if spent + estimate would cross the ceiling. The
 *                   expensive step is never made.
 *  - StepCompleted: add what the provider actually billed, so the next
 *                   estimate starts from a real number.
 *
 * A throw from either listener propagates out of the agent loop and out of
 * the middleware pipeline to whoever called ->prompt().
 */
class TokenBudget
{
    /** Tokens the fixed prefix (instructions + tool schemas) costs; measured per agent+provider. */
    public int $prefixTokens = 0;

    /** Message bytes per billed token. ~3 for tool-heavy JSON context, ~4 for prose. */
    public float $charsPerToken = 3.0;

    /** @var array<string, int> billed context tokens so far, keyed by invocation id */
    private array $spent = [];

    public function __construct(public ?int $ceiling = null) {}

    public function starting(StartingStep $event): void
    {
        if ($this->ceiling === null) {
            return;
        }

        $encoded = json_encode($event->messages) ?: serialize($event->messages);
        $estimate = $this->prefixTokens + (int) ceil(strlen($encoded) / $this->charsPerToken);
        $spent = $this->spent[$event->invocationId] ?? 0;

        if ($spent + $estimate > $this->ceiling) {
            throw TokenBudgetExceeded::beforeStep($event->stepNumber + 1, $spent, $estimate, $this->ceiling);
        }
    }

    public function completed(StepCompleted $event): void
    {
        $usage = $event->response->usage;

        $this->spent[$event->invocationId] = ($this->spent[$event->invocationId] ?? 0)
            + $usage->promptTokens + $usage->cacheWriteInputTokens + $usage->cacheReadInputTokens;

        if ($this->ceiling !== null && $this->spent[$event->invocationId] > $this->ceiling) {
            throw TokenBudgetExceeded::afterStep($event->stepNumber + 1, $this->spent[$event->invocationId], $this->ceiling);
        }
    }

    public function spent(string $invocationId): int
    {
        return $this->spent[$invocationId] ?? 0;
    }
}
