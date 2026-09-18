<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Conversational;
use Shipfastlabs\Toolkit\Firecrawl\FirecrawlCrawl;

/**
 * The research agent as you would queue it: remembered, so the run has
 * somewhere to land, and with one tool that spends money when it runs.
 *
 * FirecrawlCrawl starts a crawl and returns an id. Every start is a charge.
 * That is the side effect a retried job repeats.
 */
class QueuedResearchAgent extends ResearchAgent implements Conversational
{
    use RemembersConversations;

    public function instructions(): string
    {
        return parent::instructions()."\n\n".<<<'TXT'
            One more step, after the scrape and before the database check: start
            ONE small crawl (limit 3) of the site behind the most relevant result so
            its other pages can be collected later. Report the crawl id in your
            answer. Do not poll it and do not wait for it.
            TXT;
    }

    public function tools(): iterable
    {
        return [...parent::tools(), new FirecrawlCrawl];
    }
}
