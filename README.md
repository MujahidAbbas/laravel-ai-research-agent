# Laravel AI Research Agent

A small, runnable Laravel app that composes tools from the [Laravel AI SDK toolkit](https://toolkit.shipfastlabs.com) into one agent. Companion code for the post **[Wiring Tools to a Laravel AI Agent Is the Easy Part](https://mujahidabbas.dev/blog/laravel-ai-agent-tools-in-practice/)**.

Give it a topic. It searches the web for what developers are saying (Firecrawl), scrapes the best sources, and cross-checks a `posts` table of already-published articles so it doesn't suggest something you've already written. The point isn't the agent. It's what composing several tools into one loop teaches you that the per-tool docs never mention.

## The agent

Three tools, wired in [`app/Console/Commands/ResearchCommand.php`](app/Console/Commands/ResearchCommand.php):

- `FirecrawlSearch` and `FirecrawlScrape` — read the open web
- `DatabaseQueryTool` — a single read-only `SELECT` against your own `posts` table

```php
$agent = new AnonymousAgent(
    instructions: 'Research a blog topic. Search the web, scrape the best
        sources, then query the read-only `posts` table for gaps.',
    messages: [],
    tools: [new FirecrawlSearch, new FirecrawlScrape, new DatabaseQueryTool],
);

$response = $agent->prompt($topic, provider: 'anthropic', model: 'claude-sonnet-4-6');
```

## A real run

`php artisan research "controlling cost and token usage of Laravel AI agents"`

```text
 → FirecrawlSearch({"query":"Laravel AI agents token usage cost control"})
 → FirecrawlSearch({"query":"LLM agent token cost Reddit pain points"})
 → DatabaseQueryTool({"query":"SELECT ... FROM posts"})
 → FirecrawlScrape(reddit.com/...)   ↳ failed with status 403  (Reddit blocks scrapers)
 → FirecrawlScrape(newsletter.agentbuild.ai/...)   ↳ ok
 → FirecrawlScrape(sadiqueali.medium.com/...)      ↳ ok

 ... gap analysis + 3 recommended angles ...

Tokens: 39,382 in / 2,345 out
```

Two things that run teaches you, and the post unpacks:

- **The model paid for 39k input tokens to write 2k.** Every search result, scraped page, and DB row gets fed back into context each turn. Scope tool output before it reaches the model.
- **A scrape 403'd and the agent didn't crash.** The tool returns the error as a string, so the model read it and routed around it. Tools that throw take down the whole loop.

## Run it yourself

Requires PHP 8.4+ and a Firecrawl key (free tier works) plus an Anthropic key.

```bash
composer install
cp .env.example .env
php artisan key:generate

# add your keys to .env:
#   ANTHROPIC_API_KEY=...
#   FIRECRAWL_API_KEY=...

touch database/database.sqlite
php artisan migrate --seed        # seeds the posts table

php artisan research "your blog topic here"
```

## About the Firecrawl tools

The `FirecrawlSearch` / `FirecrawlScrape` / `FirecrawlCrawl` tools live in [`packages/toolkit-firecrawl`](packages/toolkit-firecrawl) as a local Composer path package, because they're not on Packagist yet. They're [pull request #10](https://github.com/shipfastlabs/toolkit/pull/10) against the toolkit. Once that merges and releases, drop the path repository and just:

```bash
composer require shipfastlabs/toolkit-firecrawl
```

The `DatabaseQueryTool` is already published as `shipfastlabs/toolkit-database`.

## License

MIT.
