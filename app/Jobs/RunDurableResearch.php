<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\DurableResearchAgent;
use App\Ai\Durable\CurrentRun;
use App\Ai\QueueRunLog;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The job the SDK's ->queue() does not give you: a timeout above the measured
 * worst case, explicit tries, one run per key, and a signal handler that stops
 * the agent between steps instead of letting the worker die mid-tool.
 */
class RunDurableResearch implements ShouldQueue, ShouldBeUnique, Interruptible
{
    use Queueable;

    /** Longest recorded run was 98 s; the provider timeout below is 120 s per step. */
    public int $timeout = 240;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $runKey,
        public readonly string $topic,
        public readonly string $model = 'claude-haiku-4-5-20251001',
    ) {}

    public function uniqueId(): string
    {
        return $this->runKey;
    }

    public function handle(): void
    {
        CurrentRun::begin($this->runKey);

        $agent = new DurableResearchAgent($this->runKey);

        $resuming = $agent->isResuming();

        QueueRunLog::note('durable.start', ['run_key' => $this->runKey, 'resuming' => $resuming]);

        $prompt = $resuming
            ? 'The previous worker stopped before you finished. Continue from where you were. Do not repeat tool calls whose results are already in this conversation.'
            : "Research this blog topic and recommend angles we haven't covered: {$this->topic}";

        $response = $agent->prompt($prompt, provider: 'anthropic', model: $this->model, timeout: 120);

        Storage::put("runs/{$this->runKey}-result.md", $response->text);

        QueueRunLog::note('durable.done', ['run_key' => $this->runKey, 'text_chars' => strlen($response->text), 'tool_calls' => $response->toolCalls->count()]);
    }

    /**
     * The worker got SIGTERM/SIGINT/SIGQUIT while this job was running. Ask the
     * loop to stop at the next step boundary; the exception it throws releases
     * the job and the retry resumes from the checkpoint.
     */
    public function interrupted(int $signal): void
    {
        QueueRunLog::note('durable.interrupted', ['run_key' => $this->runKey, 'signal' => $signal]);

        CurrentRun::requestStop();
    }

    public function failed(Throwable $e): void
    {
        QueueRunLog::note('durable.failed', ['run_key' => $this->runKey, 'exception' => $e::class, 'message' => $e->getMessage()]);
    }
}
