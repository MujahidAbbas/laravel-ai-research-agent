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
     * The arguments that make two calls the same side effect. The instructions
     * ask for one crawl per run, so the run alone identifies it: on resume the
     * model re-plans and may crawl another URL. A scrape of the same URL is the
     * same read, whatever formats it asks for. A tool not listed here keys on
     * all of its arguments.
     */
    private const KEY_ARGUMENTS = [
        FirecrawlCrawl::class => [],
        FirecrawlScrape::class => ['url'],
    ];

    /**
     * Tools whose interrupted call is not repeated. A scrape that may have run is
     * cheap to run again; a crawl that may have started is a second charge.
     */
    private const AT_MOST_ONCE = [
        FirecrawlCrawl::class,
    ];

    public function __construct(public readonly string $runKey)
    {
        parent::__construct();
    }

    public function tools(): iterable
    {
        foreach (parent::tools() as $tool) {
            yield $tool instanceof Tool
                ? new IdempotentTool(
                    $tool,
                    $this->runKey,
                    keyArguments: self::KEY_ARGUMENTS[$tool::class] ?? null,
                    atMostOnce: in_array($tool::class, self::AT_MOST_ONCE, true),
                )
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
