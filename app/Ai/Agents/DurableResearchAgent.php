<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Durable\IdempotentTool;
use App\Ai\Durable\StepCheckpoint;
use Laravel\Ai\Contracts\Tool;
use Shipfastlabs\Toolkit\Firecrawl\FirecrawlCrawl;
use Shipfastlabs\Toolkit\Firecrawl\FirecrawlScrape;

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
    /**
     * The arguments that make two calls the same side effect. A crawl of the
     * same URL is the same crawl, whatever the model wrote in its prompt this
     * time. A tool not listed here keys on all of its arguments.
     */
    private const KEY_ARGUMENTS = [
        FirecrawlCrawl::class => ['url'],
        FirecrawlScrape::class => ['url'],
    ];

    public function __construct(public readonly string $runKey)
    {
        parent::__construct();
    }

    public function tools(): iterable
    {
        foreach (parent::tools() as $tool) {
            yield $tool instanceof Tool
                ? new IdempotentTool($tool, $this->runKey, self::KEY_ARGUMENTS[$tool::class] ?? [])
                : $tool;
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
