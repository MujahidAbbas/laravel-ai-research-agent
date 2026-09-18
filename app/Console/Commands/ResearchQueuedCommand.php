<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\QueuedResearchAgent;
use App\Ai\QueueRunLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Queue the research agent the way the docs show it: ->queue()->then()->catch().
 * Then run a worker against it with a timeout that lands mid-run and read
 * storage/app/private/runs/queue-log.jsonl.
 */
class ResearchQueuedCommand extends Command
{
    protected $signature = 'ai:research-queued {topic : The blog topic to research}
        {--model=claude-haiku-4-5-20251001 : Anthropic model id}
        {--user=1 : User id the conversation belongs to}';

    protected $description = 'Dispatch the research agent through the SDK\'s ->queue() and record what the worker does to it';

    public function handle(): int
    {
        $topic = (string) $this->argument('topic');
        $user = User::findOrFail((int) $this->option('user'));
        $model = (string) $this->option('model');

        QueueRunLog::note('dispatch', ['topic' => $topic, 'model' => $model, 'mode' => 'sdk']);

        (new QueuedResearchAgent)
            ->forUser($user)
            ->queue(
                "Research this blog topic and recommend angles we haven't covered: {$topic}",
                provider: 'anthropic',
                model: $model,
            )
            ->then(function (AgentResponse $response): void {
                QueueRunLog::note('then', [
                    'text_chars' => strlen($response->text),
                    'conversation' => $response->conversationId,
                    'tool_calls' => $response->toolCalls->count(),
                ]);
            })
            ->catch(function (Throwable $e): void {
                QueueRunLog::note('catch', ['exception' => $e::class, 'message' => $e->getMessage()]);
            });

        // PendingDispatch pushes on destruct, so the job row exists by now.
        $job = DB::table('jobs')->latest('id')->first();
        $payload = json_decode($job->payload, true);

        $this->info("Queued {$payload['displayName']} as job {$payload['uuid']} (row {$job->id}, attempts {$job->attempts}).");
        $this->line('Log: '.storage_path('app/private/'.QueueRunLog::path()));

        return self::SUCCESS;
    }
}
