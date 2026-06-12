<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Shipfastlabs\Toolkit\Database\DatabaseQueryTool;
use Shipfastlabs\Toolkit\Firecrawl\FirecrawlScrape;
use Shipfastlabs\Toolkit\Firecrawl\FirecrawlSearch;

/**
 * A content-research agent. Given a topic, it mines the web for what the
 * community is saying (Firecrawl) and cross-checks our own published posts
 * (read-only DatabaseQueryTool) to find angles we have NOT covered yet.
 */
class ResearchCommand extends Command
{
    protected $signature = 'research {topic : The blog topic to research}';

    protected $description = 'Research a blog topic: mine the web + check our own posts for gaps';

    public function handle(): int
    {
        $topic = (string) $this->argument('topic');

        // Render the agent's tool-call loop as it happens — this is the part
        // the per-tool docs never show: the model choosing tools in sequence.
        Event::listen(InvokingTool::class, function (InvokingTool $e): void {
            $this->line('');
            $this->line('  → '.class_basename($e->tool).'('.$this->short(json_encode($e->arguments), 140).')');
        });
        Event::listen(ToolInvoked::class, function (ToolInvoked $e): void {
            $this->line('    ↳ '.$this->short((string) $e->result, 160));
        });

        $agent = new AnonymousAgent(
            instructions: <<<'TXT'
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
                TXT,
            messages: [],
            tools: [
                new FirecrawlSearch,
                new FirecrawlScrape,
                new DatabaseQueryTool,
            ],
        );

        $this->info("Researching: {$topic}");

        $response = $agent->prompt(
            "Research this blog topic and recommend angles we haven't covered: {$topic}",
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            timeout: 180,
        );

        $this->newLine();
        $this->line(str_repeat('─', 70));
        $this->line($response->text);
        $this->line(str_repeat('─', 70));
        $this->comment(sprintf(
            'Tokens: %d in / %d out',
            $response->usage->promptTokens ?? 0,
            $response->usage->completionTokens ?? 0,
        ));

        return self::SUCCESS;
    }

    private function short(string $value, int $max): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }
}
