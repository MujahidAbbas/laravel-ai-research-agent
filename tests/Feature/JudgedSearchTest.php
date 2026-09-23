<?php

namespace Tests\Feature;

use App\Ai\PageJudge;
use App\Ai\Tools\JudgedSearch;
use App\Models\SearchJudgement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Tests\TestCase;

class JudgedSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.firecrawl.key' => 'fc-test']);
    }

    public function test_it_keeps_briefs_and_drops_by_relevance(): void
    {
        $this->fakeSearch(['https://a.test', 'https://b.test', 'https://c.test']);

        Classification::fake(fn (ClassificationPrompt $prompt) => match ($prompt->state['url']) {
            'https://a.test' => $this->answers(relevant: 0.92),
            'https://b.test' => $this->answers(relevant: 0.5),
            'https://c.test' => $this->answers(relevant: 0.1),
        });

        $out = $this->search();

        $this->assertSame(['https://a.test'], array_column($out['pages'], 'url'));
        $this->assertSame(['https://b.test'], array_column($out['unsure'], 'url'));
        $this->assertSame(1, $out['dropped']);
        $this->assertArrayNotHasKey('content', $out['unsure'][0]);
    }

    public function test_a_likely_injection_is_dropped_even_when_relevant(): void
    {
        $this->fakeSearch(['https://a.test']);

        Classification::fake(fn () => $this->answers(relevant: 0.99, injection: 0.85));

        $out = $this->search();

        $this->assertSame([], $out['pages']);
        $this->assertSame(1, $out['dropped']);
    }

    public function test_it_fails_open_when_jev_errors(): void
    {
        $this->fakeSearch(['https://a.test']);

        Classification::fake(fn () => throw new RuntimeException('upstream 503'));

        $out = $this->search();

        $this->assertSame(['https://a.test'], array_column($out['pages'], 'url'));
        $this->assertDatabaseHas('search_judgements', ['url' => 'https://a.test', 'verdict' => 'unjudged', 'error' => 'upstream 503']);
    }

    public function test_it_stores_every_probability_not_just_the_verdict(): void
    {
        $this->fakeSearch(['https://a.test']);

        Classification::fake(fn () => $this->answers(relevant: 0.81, evidence: 0.4, injection: 0.02));

        $this->search();

        $row = SearchJudgement::sole();
        $this->assertSame([0.81, 0.4, 0.02, 'keep'], [$row->relevant, $row->has_evidence, $row->injection, $row->verdict]);
        $this->assertNotNull($row->model);
    }

    public function test_the_page_is_truncated_before_it_is_judged(): void
    {
        $this->fakeSearch(['https://a.test'], markdown: str_repeat('x', 50_000));

        Classification::fake(fn () => $this->answers(relevant: 0.9));

        $this->search();

        Classification::assertClassified(
            fn (ClassificationPrompt $prompt) => mb_strlen($prompt->state['page']) <= PageJudge::MAX_CHARS + 3
                && $prompt->state['research_topic'] === 'laravel queue timeouts'
        );
        $this->assertSame(50_000, SearchJudgement::sole()->content_chars);
    }

    public function test_no_results_means_no_classification(): void
    {
        $this->fakeSearch([]);

        Classification::fake();

        $this->search();

        Classification::assertNothingClassified();
    }

    public function test_the_fake_answers_cover_every_question_the_judge_asks(): void
    {
        // A fake that omits a key gets a random probability back from the SDK,
        // so a test faking only "relevant" would pass or fail at random.
        $this->assertSame(array_keys(PageJudge::questions()), array_keys($this->answers(relevant: 0.5)));
    }

    /**
     * @return array<string, BooleanAnswer>
     */
    private function answers(float $relevant, float $evidence = 0.5, float $injection = 0.0): array
    {
        return [
            'relevant' => new BooleanAnswer($relevant),
            'has_evidence' => new BooleanAnswer($evidence),
            'injection' => new BooleanAnswer($injection),
        ];
    }

    private function fakeSearch(array $urls, string $markdown = '# Page'): void
    {
        Http::fake(['api.firecrawl.dev/v2/search' => Http::response([
            'success' => true,
            'data' => ['web' => array_map(fn (string $url, int $i) => [
                'url' => $url, 'title' => "Title {$i}", 'description' => "About {$i}", 'position' => $i + 1, 'markdown' => $markdown,
            ], $urls, array_keys($urls))],
        ])]);
    }

    private function search(): array
    {
        return json_decode((string) (new JudgedSearch)->handle(new Request(['query' => 'laravel queue timeouts'])), true);
    }
}
