<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Durable\IdempotentTool;
use App\Ai\Durable\StepCheckpoint;
use Laravel\Ai\Contracts\Tool;

/**
 * The queued research agent, made safe to kill.
 *
 * Every tool is wrapped in the ledger, and messages() replays the last
 * checkpoint for this run so a retry continues instead of restarting.
 * Defining messages() here shadows RemembersConversations' version on
 * purpose: this agent's history is the run's checkpoint, not a conversation.
 */
class DurableResearchAgent extends QueuedResearchAgent
{
    public function __construct(public readonly string $runKey)
    {
        parent::__construct();
    }

    public function tools(): iterable
    {
        foreach (parent::tools() as $tool) {
            yield $tool instanceof Tool ? new IdempotentTool($tool, $this->runKey) : $tool;
        }
    }

    public function messages(): iterable
    {
        return StepCheckpoint::latest($this->runKey)['messages'] ?? [];
    }

    public function isResuming(): bool
    {
        return StepCheckpoint::latest($this->runKey) !== null;
    }
}
