<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\PageJudge;
use App\Models\SearchJudgement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Shipfastlabs\Toolkit\Firecrawl\FirecrawlSearch;
use Stringable;
use Throwable;

/**
 * Wraps the toolkit's FirecrawlSearch (never edited) with a Jev gate.
 * Every result is scraped, judged, and stored; the agent only sees what passes.
 */
class JudgedSearch implements Tool
{
    public function __construct(
        // Chosen from 77 panel-labelled pages (research.md §7), not by taste.
        public float $keepAt = 0.5,
        public float $dropBelow = 0.2,
        public float $injectionAt = 0.98,
        public string $topic = '',
        public ?string $runKey = null,
        public ?PageJudge $judge = null,
        public ?FirecrawlSearch $search = null,
    ) {
        $this->judge ??= new PageJudge;
        $this->search ??= new FirecrawlSearch;
    }

    /** @var array<string, true> URLs already handed to the agent in this run. */
    private array $sent = [];

    public function description(): Stringable|string
    {
        return <<<'TXT'
            Search the web. Every result is read and judged before you see it:
            "pages" are relevant results with their full content, "unsure" results
            come with title, URL and description only, and "dropped" is how many
            results were judged not worth reading. "already_sent" lists URLs you
            received from an earlier search in this run.
            TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The search query to look up on the web.')->required(),
            'limit' => $schema->integer()->description('Maximum number of results to judge (1-10, default: 5).')->nullable()->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request->string('query'));

        $raw = $this->search->handle(new Request([
            'query' => $query,
            'limit' => min(max((int) ($request['limit'] ?? 5), 1), 10),
            'sources' => 'web',
            'scrape_content' => true,
        ]));

        $results = json_decode($raw, true)['data']['web'] ?? null;

        if (! is_array($results)) {
            return $raw; // the toolkit's own error message, unchanged
        }

        $out = ['query' => $query, 'pages' => [], 'unsure' => [], 'dropped' => 0, 'already_sent' => []];

        foreach ($results as $page) {
            if (isset($this->sent[$page['url']])) {
                $out['already_sent'][] = $page['url'];

                continue;
            }

            $this->sent[$page['url']] = true;

            $verdict = $this->judgeAndStore($query, $page);

            match ($verdict) {
                'keep' => $out['pages'][] = ['title' => $page['title'] ?? null, 'url' => $page['url'], 'content' => $page['markdown'] ?? ''],
                'brief', 'unjudged' => $out['unsure'][] = ['title' => $page['title'] ?? null, 'url' => $page['url'], 'description' => $page['description'] ?? null],
                'drop' => $out['dropped']++,
            };
        }

        return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function judgeAndStore(string $query, array $page): string
    {
        $row = [
            'run_key' => $this->runKey,
            'topic' => $this->topic ?: $query,
            'query' => $query,
            'url' => $page['url'],
            'title' => $page['title'] ?? null,
            'description' => $page['description'] ?? null,
            'position' => $page['position'] ?? null,
            'content_chars' => mb_strlen($page['markdown'] ?? ''),
            'judged_chars' => mb_strlen($excerpt = PageJudge::excerpt($page)),
            'excerpt' => $excerpt,
        ];

        if (trim($row['excerpt']) === '') {
            // Nothing to read. Jev would judge the title alone, and confidently.
            SearchJudgement::create([...$row, 'verdict' => 'brief', 'error' => 'empty page']);

            return 'brief';
        }

        $started = hrtime(true);

        try {
            $response = $this->judge->judge($this->topic ?: $query, $query, $page);
        } catch (Throwable $e) {
            // Fail to "unsure": the agent still learns the page exists, without paying to read it.
            Log::warning('Could not judge a search result.', ['url' => $page['url'], 'error' => $e->getMessage()]);

            SearchJudgement::create([...$row, 'verdict' => 'unjudged', 'error' => $e->getMessage()]);

            return 'unjudged';
        }

        $relevant = $response['relevant']->probability;
        $injection = $response['injection']->probability;

        $verdict = match (true) {
            $injection >= $this->injectionAt => 'drop',
            $relevant >= $this->keepAt => 'keep',
            $relevant < $this->dropBelow => 'drop',
            default => 'brief',
        };

        SearchJudgement::create([
            ...$row,
            'relevant' => $relevant,
            'injection' => $injection,
            'verdict' => $verdict,
            'model' => $response->meta->model,
            'input_tokens' => $response->usage->inputTokens,
            'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        return $verdict;
    }
}
