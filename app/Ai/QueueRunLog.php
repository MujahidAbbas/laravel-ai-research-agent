<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerInterrupted;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * One JSONL line per queue and agent event, with the pid and the job attempt
 * on every line. This is the only record that survives a worker being killed:
 * the SDK's own persistence happens after the run returns, and a SIGKILL
 * never lets it return.
 */
class QueueRunLog
{
    public const EVENTS = [
        JobProcessing::class, JobProcessed::class, JobFailed::class, JobExceptionOccurred::class,
        JobReleasedAfterException::class, JobTimedOut::class, WorkerStopping::class, WorkerInterrupted::class,
        StartingStep::class, StepCompleted::class, StepFailed::class,
        InvokingTool::class, ToolInvoked::class, ToolFailed::class,
        AgentPrompted::class, AgentFailed::class,
    ];

    private static ?string $jobUuid = null;

    private static ?int $attempt = null;

    public static function path(): string
    {
        return env('QUEUE_RUN_LOG', 'runs/queue-log.jsonl');
    }

    public function record(object $event): void
    {
        $line = match (true) {
            $event instanceof JobProcessing => $this->job('job.processing', $event->job),
            $event instanceof JobProcessed => $this->job('job.processed', $event->job),
            $event instanceof JobFailed => $this->job('job.failed', $event->job, ['exception' => $event->exception::class, 'message' => $event->exception->getMessage()]),
            $event instanceof JobExceptionOccurred => $this->job('job.exception', $event->job, ['exception' => $event->exception::class, 'message' => $event->exception->getMessage()]),
            $event instanceof JobReleasedAfterException => $this->job('job.released', $event->job, ['backoff' => $event->backoff]),
            $event instanceof JobTimedOut => $this->job('job.timed_out', $event->job, ['timeout' => $event->timeout]),
            $event instanceof WorkerStopping => ['event' => 'worker.stopping', 'status' => $event->status, 'reason' => $event->reason?->value, 'description' => $event->reason?->description(), 'jobs_processed' => $event->jobsProcessed],
            $event instanceof WorkerInterrupted => ['event' => 'worker.interrupted', 'signal' => $event->signal],
            $event instanceof StartingStep => ['event' => 'step.starting', 'invocation' => $event->invocationId, 'step' => $event->stepNumber + 1, 'final' => $event->isFinalStep, 'messages' => count($event->messages), 'model' => $event->model],
            $event instanceof StepCompleted => ['event' => 'step.completed', 'invocation' => $event->invocationId, 'step' => $event->stepNumber + 1, 'tool_calls' => array_map(fn ($c) => $c->name, $event->response->toolCalls), 'finish' => $event->response->finishReason->value, 'provider_ms' => (int) round($event->time), 'prompt_tokens' => $event->response->usage->inputTokens, 'output_tokens' => $event->response->usage->outputTokens],
            $event instanceof StepFailed => ['event' => 'step.failed', 'invocation' => $event->invocationId, 'step' => $event->stepNumber + 1, 'exception' => $event->exception::class],
            $event instanceof InvokingTool => ['event' => 'tool.invoking', 'invocation' => $event->invocationId, 'tool_invocation' => $event->toolInvocationId, 'tool' => ToolNameResolver::resolve($event->tool), 'args' => $event->arguments, 'args_sha' => self::sha($event->arguments)],
            $event instanceof ToolInvoked => ['event' => 'tool.invoked', 'invocation' => $event->invocationId, 'tool_invocation' => $event->toolInvocationId, 'tool' => ToolNameResolver::resolve($event->tool), 'args_sha' => self::sha($event->arguments), 'result_chars' => strlen((string) $event->result), 'result_head' => mb_substr((string) $event->result, 0, 400), 'ms' => (int) round($event->time)],
            $event instanceof ToolFailed => ['event' => 'tool.failed', 'invocation' => $event->invocationId, 'tool' => ToolNameResolver::resolve($event->tool), 'exception' => $event->exception::class, 'ms' => (int) round($event->time)],
            $event instanceof AgentPrompted => ['event' => 'agent.prompted', 'invocation' => $event->invocationId, 'text_chars' => strlen($event->response->text), 'conversation' => $event->response->conversationId ?? null],
            $event instanceof AgentFailed => ['event' => 'agent.failed', 'invocation' => $event->invocationId, 'exception' => $event->exception::class, 'message' => $event->exception->getMessage()],
            default => null,
        };

        if ($line !== null) {
            self::write($line);
        }
    }

    /**
     * Anything else worth a line: the then/catch callbacks, a command's markers.
     */
    public static function note(string $event, array $fields = []): void
    {
        self::write(['event' => $event, ...$fields]);
    }

    private function job(string $event, $job, array $extra = []): array
    {
        if ($event === 'job.processing') {
            self::$jobUuid = $job->uuid();
            self::$attempt = $job->attempts();
        }

        return ['event' => $event, 'job' => $job->uuid(), 'attempt' => $job->attempts(), 'name' => $job->resolveName(), ...$extra];
    }

    /** Same key the ledger uses: sorted arguments, slash-safe JSON, first 8 hex chars. */
    public static function sha(array $arguments): string
    {
        ksort($arguments);

        return substr(sha1(json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 8);
    }

    private static function write(array $line): void
    {
        $line = [
            't' => now()->format('H:i:s.v'),
            'pid' => getmypid(),
            'job' => $line['job'] ?? self::$jobUuid,
            'attempt' => $line['attempt'] ?? self::$attempt,
            ...$line,
        ];

        Storage::append(self::path(), json_encode($line, JSON_UNESCAPED_SLASHES));
    }
}
