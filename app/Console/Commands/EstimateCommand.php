<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The token math nobody runs before launch.
 *
 * Every step of a tool loop re-sends everything before it. With a fixed
 * prefix S and u new tokens per step, step k sends S + k·u, so N steps send
 * N·S + u·N(N−1)/2, not N·(S+u). This prints both, in tokens and dollars,
 * so the number goes in the estimate instead of on the invoice.
 *
 * Rates are $/1M tokens and are only as current as the day you type them.
 */
class EstimateCommand extends Command
{
    protected $signature = 'ai:estimate
        {--prefix=1345 : Tokens for instructions + tool schemas (measure it: step 1 of a real run)}
        {--per-step=8000 : New tokens each step adds (tool results + the assistant turn)}
        {--steps=5 : MaxSteps for the agent (the SDK default with no attribute is 5)}
        {--output=500 : Output tokens per step}
        {--input-rate=3.00 : $/1M input tokens (Sonnet 4.6: 3.00, Sonnet 5: 2.00, gpt-5.6-terra: 2.00)}
        {--output-rate=15.00 : $/1M output tokens (Sonnet 4.6: 15.00, Sonnet 5: 10.00, gpt-5.6-terra: 12.00)}
        {--runs=1000 : Runs per month, for the monthly line}';

    protected $description = 'Estimate what an agent run costs when every step re-sends the whole context';

    public function handle(): int
    {
        $prefix = (int) $this->option('prefix');
        $perStep = (int) $this->option('per-step');
        $steps = max(1, (int) $this->option('steps'));
        $output = (int) $this->option('output');
        $inputRate = (float) $this->option('input-rate');
        $outputRate = (float) $this->option('output-rate');
        $runs = (int) $this->option('runs');

        $rows = [];
        $cumulative = 0;

        for ($k = 0; $k < $steps; $k++) {
            $sent = $prefix + $k * $perStep;
            $cumulative += $sent;
            $rows[] = [$k + 1, number_format($sent), number_format($cumulative)];
        }

        $naive = $steps * ($prefix + $perStep);
        $actual = $cumulative;

        $this->table(['Step', 'Context sent', 'Cumulative'], $rows);

        $dollars = fn (int $input) => ($input * $inputRate + $steps * $output * $outputRate) / 1_000_000;

        $this->newLine();
        $this->line(sprintf('Per-request estimate (%d steps × (prefix + per-step)): %s input tokens  → $%.4f per run', $steps, number_format($naive), $dollars($naive)));
        $this->line(sprintf('What the loop actually sends (N·S + u·N(N−1)/2):           %s input tokens  → $%.4f per run', number_format($actual), $dollars($actual)));
        $this->line(sprintf('Ratio: %.1f×   Last step alone: %s tokens, %.0f%% of the run', $actual / max(1, $naive), number_format($prefix + ($steps - 1) * $perStep), 100 * ($prefix + ($steps - 1) * $perStep) / $actual));
        $this->newLine();
        $this->comment(sprintf('At %s runs/month: $%.2f estimated, $%.2f actual. Doubling MaxSteps to %d: $%.2f.',
            number_format($runs),
            $runs * $dollars($naive),
            $runs * $dollars($actual),
            $steps * 2,
            $runs * (($steps * 2 * $prefix + $perStep * ($steps * 2) * ($steps * 2 - 1) / 2) * $inputRate + $steps * 2 * $output * $outputRate) / 1_000_000,
        ));

        return self::SUCCESS;
    }
}
