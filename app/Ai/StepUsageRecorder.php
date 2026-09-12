<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;

/**
 * Records what every step of an agent run actually cost.
 *
 * Middleware wraps the whole run and sees one prompt in, one response out.
 * The only per-step hooks the SDK gives you are the StartingStep and
 * StepCompleted events, so this listens to both: StartingStep carries the
 * full message context about to be sent (the thing that grows), and
 * StepCompleted carries that step's Usage (the thing that gets billed).
 */
class StepUsageRecorder
{
    /** @var array<string, array<int, array<string, mixed>>> rows keyed by invocation id, then step number */
    private array $rows = [];

    public function starting(StartingStep $event): void
    {
        // Rough size of the context about to be sent. Calibrate against the
        // provider's promptTokens on the same step; chars/4 is close enough
        // to show the shape and is what a pre-run estimate has to work with.
        $encoded = json_encode($event->messages) ?: serialize($event->messages);

        // Chars per message class, so a run file shows how much of the
        // context is rehydrated tool results versus the conversation itself.
        $byClass = [];
        foreach ($event->messages as $message) {
            $key = class_basename($message);
            $byClass[$key] = ($byClass[$key] ?? 0) + strlen(json_encode($message) ?: '');
        }

        $this->rows[$event->invocationId][$event->stepNumber] = [
            'step' => $event->stepNumber + 1,
            'messages_sent' => count($event->messages),
            'context_chars' => strlen($encoded),
            'context_est_tokens' => intdiv(strlen($encoded), 4),
            'context_chars_by_class' => $byClass,
        ];
    }

    public function completed(StepCompleted $event): void
    {
        $usage = $event->response->usage;

        $this->rows[$event->invocationId][$event->stepNumber] = array_merge(
            $this->rows[$event->invocationId][$event->stepNumber] ?? ['step' => $event->stepNumber + 1],
            [
                'tool_calls' => count($event->response->toolCalls),
                'tools' => array_map(fn ($call) => $call->name, $event->response->toolCalls),
                'prompt_tokens' => $usage->promptTokens,
                'cache_write_tokens' => $usage->cacheWriteInputTokens,
                'cache_read_tokens' => $usage->cacheReadInputTokens,
                // The provider bills all three; promptTokens alone is only the
                // uncached remainder on both Anthropic and OpenAI.
                'context_tokens' => $usage->promptTokens + $usage->cacheWriteInputTokens + $usage->cacheReadInputTokens,
                'completion_tokens' => $usage->completionTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'ms' => (int) round($event->time),
                // The provider's own usage block, so a mapping bug in the SDK is visible.
                'raw_usage' => $event->response->raw?->json('usage'),
                'model' => $event->model,
                'provider' => $event->provider->name(),
            ],
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(?string $invocationId = null): array
    {
        $rows = $invocationId === null
            ? (end($this->rows) ?: [])
            : ($this->rows[$invocationId] ?? []);

        ksort($rows);

        return array_values($rows);
    }

    /** @return array<string, int> */
    public function totals(?string $invocationId = null): array
    {
        $totals = ['context_tokens' => 0, 'prompt_tokens' => 0, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0, 'completion_tokens' => 0, 'steps' => 0];

        foreach ($this->rows($invocationId) as $row) {
            foreach (['context_tokens', 'prompt_tokens', 'cache_write_tokens', 'cache_read_tokens', 'completion_tokens'] as $key) {
                $totals[$key] += $row[$key] ?? 0;
            }
            $totals['steps']++;
        }

        return $totals;
    }

    /**
     * Persist the run so the numbers in a write-up are reproducible.
     */
    public function save(string $label, ?string $invocationId = null): string
    {
        $path = sprintf('runs/%s-%s.json', now()->format('Y-m-d-His'), preg_replace('/[^a-z0-9]+/i', '-', $label));

        Storage::put($path, json_encode([
            'label' => $label,
            'recorded_at' => now()->toIso8601String(),
            'steps' => $this->rows($invocationId),
            'totals' => $this->totals($invocationId),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Storage::path($path);
    }
}
