<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Shipfastlabs\Toolkit\Database\DatabaseQueryTool;
use Shipfastlabs\Toolkit\Firecrawl\FirecrawlScrape;
use Shipfastlabs\Toolkit\Firecrawl\FirecrawlSearch;

/**
 * The content-research agent from the tools post, as a named class so it can
 * carry provider options and caching attributes. Behaviour is unchanged.
 */
class ResearchAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    /**
     * 'default' leaves each provider's caching behaviour alone.
     * 'off'     disables prompt caching where the provider lets you.
     * 'auto'    turns on Anthropic's advancing top-level breakpoint.
     */
    public function __construct(public string $caching = 'default') {}

    public function instructions(): string
    {
        return <<<'TXT'
            You are a content-research assistant for a Laravel + AI engineering blog.

            Your job: given a topic, decide whether it is worth writing and find a
            non-obvious angle, using your tools.

            Workflow:
            1. Use the web search tool to find what developers are actually saying
               about the topic (Reddit, forums, blogs).
            2. Scrape the single most relevant result for concrete detail.
            3. Query our own published posts to see what we have ALREADY covered, so
               you do not propose a duplicate. The database has a read-only `posts`
               table with columns: id, title, slug, description, tags
               (comma-separated), published_at. It accepts a single SELECT only.
            4. Recommend 2-3 specific angles we have NOT published yet, and for each,
               name the gap it fills versus both the community discussion and our
               existing posts.

            Be concrete and concise. Prefer angles backed by a real pain point you
            found in the search.
            TXT;
    }

    public function tools(): iterable
    {
        return [
            new FirecrawlSearch,
            new FirecrawlScrape,
            new DatabaseQueryTool,
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ([$provider, $this->caching]) {
            // GPT-5.6+: explicit mode with no breakpoints = no cache writes, no reads.
            [Lab::OpenAI, 'off'] => ['prompt_cache_options' => ['mode' => 'explicit']],
            // Anthropic only caches when asked; this is the advancing breakpoint.
            [Lab::Anthropic, 'auto'] => ['cache_control' => ['type' => 'ephemeral']],
            default => [],
        };
    }
}
