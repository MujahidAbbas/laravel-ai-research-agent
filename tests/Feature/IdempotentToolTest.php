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

    /**
     * Run D2 (2026-10-07): attempt 2 re-planned the step and crawled another URL,
     * so a URL key missed it too. The run asks for one crawl; the run is the key.
     */
    public function test_a_second_crawl_in_the_same_run_maps_to_the_first_whatever_its_url(): void
    {
        $crawl = $this->wrapped('FirecrawlCrawl');

        $crawl->handle(new Request(['url' => 'https://medium.com/@Modexa/tool-calling-ai-in-prod-10-reliability-controls-ab1fffee9cdb', 'limit' => 3, 'prompt' => 'articles about LLM tool calling reliability, idempotency, duplicate prevention, production safeguards']));
        $crawl->handle(new Request(['url' => 'https://dev.to/aws/how-to-prevent-ai-agent-reasoning-loops-from-wasting-tokens-2652', 'limit' => 3, 'prompt' => 'tool calling duplicate prevention idempotency retries']));

        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('agent_tool_invocations')->where('tool', 'FirecrawlCrawl')->count());
    }

    public function test_another_run_gets_its_own_crawl(): void
    {
        $this->wrapped('FirecrawlCrawl')->handle(new Request(['url' => 'https://pub.towardsai.net', 'limit' => 3, 'prompt' => 'Laravel queues']));
        $this->wrapped('FirecrawlCrawl', '01TESTRUNKEY0000000000000B')->handle(new Request(['url' => 'https://pub.towardsai.net', 'limit' => 3, 'prompt' => 'Laravel queues']));

        Http::assertSentCount(2);
    }

    public function test_a_scrape_keys_on_its_url_alone(): void
    {
        $scrape = $this->wrapped('FirecrawlScrape');

        $scrape->handle(new Request(['url' => 'https://arxiv.org/html/2608.02645v1', 'formats' => 'markdown', 'only_main_content' => true]));
        $scrape->handle(new Request(['url' => 'https://arxiv.org/html/2608.02645v1', 'formats' => 'markdown,links', 'only_main_content' => false]));
        $scrape->handle(new Request(['url' => 'https://hafiqiqmal93.medium.com/post', 'formats' => 'markdown']));

        Http::assertSentCount(2);
        $this->assertSame(2, DB::table('agent_tool_invocations')->where('tool', 'FirecrawlScrape')->count());
    }

    public function test_a_tool_with_no_declared_key_still_hashes_every_argument(): void
    {
        $search = $this->wrapped('FirecrawlSearch');

        $search->handle(new Request(['query' => 'Laravel queued agent retries', 'limit' => 5]));
        $search->handle(new Request(['query' => 'Laravel queued agent retries', 'limit' => 8]));

        Http::assertSentCount(2);
        $this->assertSame(2, DB::table('agent_tool_invocations')->where('tool', 'FirecrawlSearch')->count());
    }

    /**
     * D2's row 62: the kill landed during the crawl request, so attempt 1's
     * claim has no result and nobody knows whether Firecrawl started it.
     */
    public function test_an_interrupted_crawl_is_not_started_again(): void
    {
        $this->claimedRow('FirecrawlCrawl', [], ['limit' => 3, 'prompt' => 'articles about LLM tool calling reliability, idempotency, duplicate prevention, production safeguards', 'url' => 'https://medium.com/@Modexa/tool-calling-ai-in-prod-10-reliability-controls-ab1fffee9cdb']);

        $result = $this->wrapped('FirecrawlCrawl')->handle(new Request(['url' => 'https://dev.to/aws/how-to-prevent-ai-agent-reasoning-loops-from-wasting-tokens-2652', 'limit' => 3, 'prompt' => 'tool calling duplicate prevention idempotency retries']));

        Http::assertNothingSent();
        $this->assertStringContainsString('may or may not have run', (string) $result);
        $this->assertSame('claimed', DB::table('agent_tool_invocations')->where('tool', 'FirecrawlCrawl')->value('status'));
    }

    public function test_an_interrupted_scrape_runs_again(): void
    {
        $this->claimedRow('FirecrawlScrape', ['url' => 'https://pub.towardsai.net/post'], ['formats' => 'markdown', 'url' => 'https://pub.towardsai.net/post']);

        $this->wrapped('FirecrawlScrape')->handle(new Request(['url' => 'https://pub.towardsai.net/post', 'formats' => 'markdown']));

        Http::assertSentCount(1);
        $this->assertSame('done', DB::table('agent_tool_invocations')->where('tool', 'FirecrawlScrape')->value('status'));
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $args
     */
    private function claimedRow(string $tool, array $key, array $args): void
    {
        DB::table('agent_tool_invocations')->insert([
            'run_key' => '01TESTRUNKEY0000000000000A',
            'tool' => $tool,
            'args_sha' => sha1(json_encode($key, JSON_UNESCAPED_SLASHES)),
            'args' => json_encode($args, JSON_UNESCAPED_SLASHES),
            'status' => 'claimed',
            'pid' => 61107,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function wrapped(string $name, string $runKey = '01TESTRUNKEY0000000000000A'): IdempotentTool
    {
        foreach ((new DurableResearchAgent($runKey))->tools() as $tool) {
            if ($tool instanceof IdempotentTool && $tool->name() === $name) {
                return $tool;
            }
        }

        $this->fail("DurableResearchAgent has no wrapped {$name} tool.");
    }
}
