<?php

namespace Tests\Feature;

use App\Ai\Agents\DurableResearchAgent;
use App\Ai\Durable\IdempotentTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class IdempotentToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.firecrawl.key' => 'fc-test']);

        Http::fake(['api.firecrawl.dev/*' => Http::sequence()
            ->push(['success' => true, 'id' => 'first'])
            ->push(['success' => true, 'id' => 'second']),
        ]);
    }

    /**
     * The two crawl calls from run D1x (2026-10-07): attempt 2 re-planned the
     * step and reworded the prompt, and a hash of all arguments ran it twice.
     */
    public function test_a_reworded_crawl_prompt_maps_to_the_same_ledger_key(): void
    {
        $crawl = $this->wrapped('FirecrawlCrawl');

        $first = $crawl->handle(new Request(['url' => 'https://pub.towardsai.net', 'limit' => 3, 'prompt' => 'AI agents, Laravel, queue, LLM tool calling']));
        $second = $crawl->handle(new Request(['url' => 'https://pub.towardsai.net', 'limit' => 3, 'prompt' => 'Laravel queue jobs LLM agents AI']));

        Http::assertSentCount(1);
        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('agent_tool_invocations')->where('tool', 'FirecrawlCrawl')->count());
    }

    public function test_a_crawl_of_another_url_is_another_side_effect(): void
    {
        $crawl = $this->wrapped('FirecrawlCrawl');

        $crawl->handle(new Request(['url' => 'https://pub.towardsai.net', 'limit' => 3, 'prompt' => 'Laravel queues']));
        $crawl->handle(new Request(['url' => 'https://laravel-news.com', 'limit' => 3, 'prompt' => 'Laravel queues']));

        Http::assertSentCount(2);
        $this->assertSame(2, DB::table('agent_tool_invocations')->where('tool', 'FirecrawlCrawl')->count());
    }

    public function test_a_tool_with_no_declared_key_still_hashes_every_argument(): void
    {
        $search = $this->wrapped('FirecrawlSearch');

        $search->handle(new Request(['query' => 'Laravel queued agent retries', 'limit' => 5]));
        $search->handle(new Request(['query' => 'Laravel queued agent retries', 'limit' => 8]));

        Http::assertSentCount(2);
        $this->assertSame(2, DB::table('agent_tool_invocations')->where('tool', 'FirecrawlSearch')->count());
    }

    private function wrapped(string $name): IdempotentTool
    {
        foreach ((new DurableResearchAgent('01TESTRUNKEY0000000000000A'))->tools() as $tool) {
            if ($tool instanceof IdempotentTool && $tool->name() === $name) {
                return $tool;
            }
        }

        $this->fail("DurableResearchAgent has no wrapped {$name} tool.");
    }
}
