<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\ResearchAgent;
use App\Ai\StepUsageRecorder;
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
        {--caching=default : default | off (OpenAI) | auto (Anthropic advancing breakpoint)}';

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

        $agent = new ResearchAgent(caching: (string) $this->option('caching'));

        $this->info("Researching: {$topic}");

        $provider = (string) $this->option('provider');
        $model = (string) $this->option('model');

        $response = $agent->prompt(
            "Research this blog topic and recommend angles we haven't covered: {$topic}",
            provider: $provider,
            model: $model,
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
