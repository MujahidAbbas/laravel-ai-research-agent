<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\QueueRunLog;
use App\Jobs\RunDurableResearch;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ResearchDurableCommand extends Command
{
    protected $signature = 'ai:research-durable {topic : The blog topic to research}
        {--model=claude-haiku-4-5-20251001 : Anthropic model id}
        {--run-key= : Re-dispatch an existing run (resumes from its checkpoint)}';

    protected $description = 'Dispatch the research agent through the owned, interruptible job with a tool ledger and step checkpoints';

    public function handle(): int
    {
        $runKey = (string) ($this->option('run-key') ?: Str::ulid());

        QueueRunLog::note('dispatch', ['topic' => $this->argument('topic'), 'model' => $this->option('model'), 'mode' => 'durable', 'run_key' => $runKey]);

        RunDurableResearch::dispatch($runKey, (string) $this->argument('topic'), (string) $this->option('model'));

        $this->info("Queued RunDurableResearch with run key {$runKey}.");

        return self::SUCCESS;
    }
}
