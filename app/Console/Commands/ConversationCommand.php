<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\ConversationalResearchAgent;
use App\Ai\History\HistoryPolicy;
use App\Ai\History\KeepEverything;
use App\Ai\History\StubStaleToolResults;
use App\Ai\History\SummaryCheckpoint;
use App\Ai\History\TokenWindow;
use App\Ai\StepUsageRecorder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * A scripted multi-turn conversation with the research agent, remembered
 * through RemembersConversations, with a history policy applied on the way
 * back in. Records what every turn sent so the growth curve, and what each
 * policy does to it, is measured rather than argued.
 */
class ConversationCommand extends Command
{
    protected $signature = 'research:conversation
        {--provider=anthropic : anthropic | openai}
        {--model=claude-sonnet-4-6 : Model id for that provider}
        {--history=all : all | window | naive | stub | summary | summary2 (summary with a preserving summariser)}
        {--budget=8000 : Token budget for window / naive / summary (chars/4 estimate)}
        {--keep-results=1 : stub: how many recent turns keep their tool results intact}
        {--turns=10 : How many of the scripted turns to run}
        {--label= : Extra label for the run file}';

    protected $description = 'Run a scripted ten-turn conversation and record what each turn re-sent';

    /**
     * Same topic as the cost-math run, then follow-ups that each pull one
     * tool result into the history (search, scrape, DB, repeat). The "one
     * tool call at most" line keeps each turn to about two steps, so the
     * curve measures what the HISTORY costs across turns, not the in-run
     * growth the cost-math post already covered.
     */
    private const TURNS = [
        "Research this blog topic and recommend angles we haven't covered: controlling cost and token usage of Laravel AI agents",
        'Scrape the most-discussed community thread or post from your search (not Reddit, it blocks scrapers) and pull out three verbatim complaints about agent cost. One tool call at most.',
        'Query our posts table: which of our published posts already touch cost, tokens, or caching? List title and slug. One tool call at most.',
        'Search for what developers say about prompt caching inside multi-step agents. Summarize the top three results in one line each. One tool call at most.',
        'Scrape the most detailed of those three and tell me what it measured, with numbers. One tool call at most.',
        'Search for complaints about using a cheaper model for sub-tasks in agents (model routing). What goes wrong? One tool call at most.',
        'Scrape the single best source on that and extract the concrete failure cases. One tool call at most.',
        'Check our posts table again: do we have anything on model routing or choosing models per step? One tool call at most.',
        'Compare the three candidate angles (cost math, caching, model routing) on the evidence you gathered so far. Which has the strongest real pain behind it? No tool calls.',
        'Write the final recommendation: five bullets, each citing one of the pages you actually scraped by URL. No tool calls.',
    ];

    public function handle(StepUsageRecorder $recorder): int
    {
        $provider = (string) $this->option('provider');
        $model = (string) $this->option('model');
        $policy = $this->policy($provider, $model);
        $turns = min((int) $this->option('turns'), count(self::TURNS));

        Event::listen(InvokingTool::class, function (InvokingTool $e): void {
            $this->line('    → '.class_basename($e->tool).'('.$this->short(json_encode($e->arguments), 100).')');
        });

        $user = User::query()->firstOrCreate(
            ['email' => 'harness@example.com'],
            ['name' => 'Harness', 'password' => 'not-a-login'],
        );

        $this->info(sprintf('Conversation: %s / %s, history=%s, %d turns', $provider, $model, $policy->name(), $turns));

        $conversationId = null;
        $rows = [];
        $error = null;

        for ($turn = 1; $turn <= $turns; $turn++) {
            $prompt = self::TURNS[$turn - 1];
            $this->newLine();
            $this->line("<comment>Turn {$turn}</comment> {$this->short($prompt, 110)}");

            $agent = new ConversationalResearchAgent(history: $policy);
            $agent = $conversationId === null
                ? $agent->forUser($user)
                : $agent->continue($conversationId, as: $user);

            $started = hrtime(true);

            try {
                $response = $this->promptWithRetry($agent, $prompt, $provider, $model);
            } catch (Throwable $e) {
                $error = ['turn' => $turn, 'exception' => $e::class, 'message' => $this->short($e->getMessage(), 600)];
                $this->error("Turn {$turn} failed: ".$e::class.': '.$this->short($e->getMessage(), 300));

                break;
            }

            $conversationId ??= $response->conversationId;

            $rows[] = $this->turnRow($turn, $response, $recorder, $policy, (int) round((hrtime(true) - $started) / 1e6));
            $this->line('    '.$this->short($response->text, 160));
        }

        $this->turnTable($rows);

        $path = $this->save($rows, $error, $provider, $model, $policy, $conversationId);
        $this->comment("Run saved: {$path}");
        $this->comment("Conversation: {$conversationId}");

        return $error === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A provider 5xx mid-conversation should not throw away nine good turns.
     * Same prompt, same agent instance, up to two retries; anything else
     * (4xx, budget, bugs) propagates.
     */
    private function promptWithRetry(ConversationalResearchAgent $agent, string $prompt, string $provider, string $model): AgentResponse
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $agent->prompt($prompt, provider: $provider, model: $model, timeout: 180);
            } catch (RequestException $e) {
                if ($attempt >= 3 || $e->response->status() < 500) {
                    throw $e;
                }

                $this->warn("    provider {$e->response->status()}, retrying ({$attempt}/2)");
                sleep(5 * $attempt);
            }
        }
    }

    private function policy(string $provider, string $model): HistoryPolicy
    {
        $budget = (int) $this->option('budget');

        return match ((string) $this->option('history')) {
            'all' => new KeepEverything,
            'window' => new TokenWindow($budget),
            'naive' => new TokenWindow($budget, dropLeadingResults: false),
            'stub' => new StubStaleToolResults((int) $this->option('keep-results')),
            'summary' => new SummaryCheckpoint($budget, provider: $provider),
            'summary2' => new SummaryCheckpoint($budget, provider: $provider, preserving: true),
            default => throw new \InvalidArgumentException('Unknown --history: '.$this->option('history')),
        };
    }

    /**
     * One row per turn. Step 1 of a turn is the one that carries the
     * history, so its context is the history bill; later steps in the same
     * turn are the in-run growth the cost-math post already measured.
     *
     * @return array<string, mixed>
     */
    private function turnRow(int $turn, AgentResponse $response, StepUsageRecorder $recorder, HistoryPolicy $policy, int $ms): array
    {
        $steps = $recorder->rows($response->invocationId);
        $totals = $recorder->totals($response->invocationId);
        $first = $steps[0] ?? [];
        $byClass = $first['context_chars_by_class'] ?? [];

        $summaryUsage = $policy instanceof SummaryCheckpoint ? $policy->lastSummaryUsage : null;

        return [
            'turn' => $turn,
            'steps' => count($steps),
            'messages_sent' => $first['messages_sent'] ?? 0,
            'history_context_tokens' => $first['context_tokens'] ?? 0,
            'history_est_tokens' => $first['context_est_tokens'] ?? 0,
            'history_chars' => $first['context_chars'] ?? 0,
            'history_tool_result_chars' => $byClass['ToolResultMessage'] ?? 0,
            'history_chars_by_class' => $byClass,
            'turn_context_tokens' => $totals['context_tokens'],
            'turn_completion_tokens' => $totals['completion_tokens'],
            // Same accounting as the recorder: OpenAI's promptTokens is only the uncached remainder.
            'summary_prompt_tokens' => $summaryUsage === null ? null : $summaryUsage->promptTokens + $summaryUsage->cacheWriteInputTokens + $summaryUsage->cacheReadInputTokens,
            'summary_uncached_prompt_tokens' => $summaryUsage?->promptTokens,
            'summary_completion_tokens' => $summaryUsage?->completionTokens,
            'ms' => $ms,
            'invocation_id' => $response->invocationId,
            'step_rows' => $steps,
            'answer' => $response->text,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function turnTable(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->table(
            ['Turn', 'Msgs', 'History (billed)', 'History (est)', 'of which tool results (est)', 'Steps', 'Turn total', 'Summary call', 'ms'],
            array_map(fn (array $r) => [
                $r['turn'],
                $r['messages_sent'],
                number_format($r['history_context_tokens']),
                number_format($r['history_est_tokens']),
                number_format(intdiv($r['history_tool_result_chars'], 4)),
                $r['steps'],
                number_format($r['turn_context_tokens']),
                $r['summary_prompt_tokens'] === null ? '—' : number_format($r['summary_prompt_tokens']).' / '.number_format($r['summary_completion_tokens']),
                number_format($r['ms']),
            ], $rows),
        );

        $this->comment(sprintf(
            'History sent across %d turns: %s billed tokens; whole run %s context tokens, %s output',
            count($rows),
            number_format(array_sum(array_column($rows, 'history_context_tokens'))),
            number_format(array_sum(array_column($rows, 'turn_context_tokens'))),
            number_format(array_sum(array_column($rows, 'turn_completion_tokens'))),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $error
     */
    private function save(array $rows, ?array $error, string $provider, string $model, HistoryPolicy $policy, ?string $conversationId): string
    {
        $label = trim(sprintf('conversation-%s-%s-%s %s', $provider, $model, $policy->name(), (string) $this->option('label')));
        $path = sprintf('runs/%s-%s.json', now()->format('Y-m-d-His'), preg_replace('/[^a-z0-9]+/i', '-', $label));

        Storage::put($path, json_encode([
            'label' => $label,
            'recorded_at' => now()->toIso8601String(),
            'provider' => $provider,
            'model' => $model,
            'history' => $policy->name(),
            'options' => ['budget' => (int) $this->option('budget'), 'keep_results' => (int) $this->option('keep-results')],
            'conversation_id' => $conversationId,
            'error' => $error,
            'turns' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Storage::path($path);
    }

    private function short(string $value, int $max): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }
}
