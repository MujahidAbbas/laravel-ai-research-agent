<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\JudgedSearch;
use Laravel\Ai\Attributes\MaxSteps;
use Shipfastlabs\Toolkit\Database\DatabaseQueryTool;

/**
 * The research agent with FirecrawlSearch + FirecrawlScrape replaced by
 * JudgedSearch. No scrape tool on purpose: an agent that can bypass the
 * filter will, and then the filter measures nothing.
 *
 * MaxSteps is pinned because the SDK derives the default from the tool count
 * (round(tools * 1.5)): three tools gave the original agent 5 steps, two give 3.
 */
#[MaxSteps(5)]
class JudgedResearchAgent extends ResearchAgent
{
    public function __construct(public JudgedSearch $search, string $caching = 'default')
    {
        parent::__construct($caching);
    }

    public function instructions(): string
    {
        return str_replace(
            [
                "1. Use the web search tool to find what developers are actually saying\n   about the topic (Reddit, forums, blogs).",
                '2. Scrape the single most relevant result for concrete detail.',
            ],
            [
                "1. Use the web search tool to find what developers are actually saying\n   about the topic (Reddit, forums, blogs). Results arrive already read and\n   filtered: use the content of \"pages\"; \"unsure\" results are title and URL only.",
                "2. If the pages are thin, search again with a sharper query. There is no\n   separate scrape tool.",
            ],
            parent::instructions(),
        );
    }

    public function tools(): iterable
    {
        return [
            $this->search,
            new DatabaseQueryTool,
        ];
    }
}
