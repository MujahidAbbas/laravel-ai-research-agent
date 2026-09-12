<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\PrefixCachedResearchAgent;
use App\Ai\Agents\ResearchAgent;
use App\Ai\Exceptions\TokenBudgetExceeded;
use App\Ai\StepUsageRecorder;
use App\Ai\TokenBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

/**
 * A content-research agent. Given a topic, it mines the web for what the
 * community is saying (Firecrawl) and cross-checks our own published posts
 * (read-only DatabaseQueryTool) to find angles we have NOT covered yet.
 */
class ResearchCommand extends Command
{
    protected $signature = 'research {topic : The blog topic to research}
        {--provider=anthropic : Provider to run on (anthropic, openai)}
        {--model=claude-sonnet-4-6 : Model id for that provider}
        {--caching=default : default | off (OpenAI) | auto (Anthropic advancing breakpoint) | prefix (Anthropic instructions + tools only)}
        {--budget= : Ceiling on context tokens the whole run may send; refuses the step that would cross it}
        {--prefix-tokens=1345 : Tokens the instructions + tool schemas cost on this provider (measured: 1,345 Sonnet 4.6, ~620 gpt-5.6-terra)}';

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

        $caching = (string) $this->option('caching');
        $agent = $caching === 'prefix' ? new PrefixCachedResearchAgent : new ResearchAgent(caching: $caching);

        $budget = resolve(TokenBudget::class);
        $budget->ceiling = $this->option('budget') !== null ? (int) $this->option('budget') : null;
        $budget->prefixTokens = (int) $this->option('prefix-tokens');

        $this->info("Researching: {$topic}");

        $provider = (string) $this->option('provider');
        $model = (string) $this->option('model');

        try {
            $response = $agent->prompt(
                "Research this blog topic and recommend angles we haven't covered: {$topic}",
                provider: $provider,
                model: $model,
                timeout: 180,
            );
        } catch (TokenBudgetExceeded $e) {
            $this->newLine();
            $this->error($e->getMessage());
            $this->stepTable($recorder = resolve(StepUsageRecorder::class));
            $this->comment('Run saved: '.$recorder->save("{$provider}-{$model}-budget-{$budget->ceiling}-refused"));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(str_repeat('─', 70));
        $this->line($response->text);
        $this->line(str_repeat('─', 70));
        $this->comment(sprintf(
            'Tokens: %d in / %d out',
            $response->usage->promptTokens ?? 0,
            $response->usage->completionTokens ?? 0,
        ));

        $this->stepTable($recorder = resolve(StepUsageRecorder::class));

        $path = $recorder->save("{$provider}-{$model}-cache-{$agent->caching}");
        $this->comment("Run saved: {$path}");

        return self::SUCCESS;
    }

    /**
     * The number the SDK reports is a sum over every step. This is the shape.
     */
    private function stepTable(StepUsageRecorder $recorder): void
    {
        $rows = $recorder->rows();

        if ($rows === []) {
            $this->warn('No step events recorded.');

            return;
        }

        $this->newLine();
        $this->table(
            ['Step', 'Tools', 'Context (sent)', 'Prompt', 'Cache write', 'Cache read', 'Out', 'ms'],
            array_map(fn (array $r) => [
                $r['step'],
                implode(', ', $r['tools'] ?? []) ?: '—',
                number_format($r['context_tokens'] ?? 0),
                number_format($r['prompt_tokens'] ?? 0),
                number_format($r['cache_write_tokens'] ?? 0),
                number_format($r['cache_read_tokens'] ?? 0),
                number_format($r['completion_tokens'] ?? 0),
                number_format($r['ms'] ?? 0),
            ], $rows),
        );

        $t = $recorder->totals();
        $this->comment(sprintf(
            'Context sent across %d steps: %s tokens (%s uncached + %s cache write + %s cache read); output %s',
            $t['steps'],
            number_format($t['context_tokens']),
            number_format($t['prompt_tokens']),
            number_format($t['cache_write_tokens']),
            number_format($t['cache_read_tokens']),
            number_format($t['completion_tokens']),
        ));
    }

    private function short(string $value, int $max): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }
}
