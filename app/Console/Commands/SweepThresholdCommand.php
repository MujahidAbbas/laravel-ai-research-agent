<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SearchJudgement;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Replays every labelled judgement against a range of thresholds, without
 * calling Jev again, and prints what each threshold would have kept and lost.
 */
class SweepThresholdCommand extends Command
{
    protected $signature = 'ai:sweep-threshold
        {--signal=relevant : relevant | has_evidence | both (the lower of the two)}';

    protected $description = 'Choose the keep threshold from labelled judgements';

    public function handle(): int
    {
        $rows = SearchJudgement::query()->where('kind', 'collected')->whereNotNull('label')->whereNotNull('relevant')->get();

        if ($rows->isEmpty()) {
            $this->error('No labelled judgements yet. Run ai:label-judgements first.');

            return self::FAILURE;
        }

        $signal = (string) $this->option('signal');
        $score = fn (SearchJudgement $r): float => match ($signal) {
            'has_evidence' => $r->has_evidence,
            'both' => min($r->relevant, $r->has_evidence),
            default => $r->relevant,
        };

        $useful = $rows->where('label', true)->count();
        $allChars = $rows->sum('content_chars');
        $this->info("{$rows->count()} labelled pages, {$useful} useful, signal: {$signal}");

        $sweep = collect(range(5, 95, 5))->map(function (int $pct) use ($rows, $score, $allChars) {
            $t = $pct / 100;
            $kept = $rows->filter(fn ($r) => $score($r) >= $t);
            $dropped = $rows->reject(fn ($r) => $score($r) >= $t);

            return [
                'threshold' => $t,
                'kept' => $kept->count(),
                'useful_kept' => $kept->where('label', true)->count(),
                'useful_lost' => $dropped->where('label', true)->count(),
                'junk_kept' => $kept->where('label', false)->count(),
                'chars_kept_pct' => $allChars ? round($kept->sum('content_chars') / $allChars * 100, 1) : 0,
            ];
        });

        $this->table(array_keys($sweep->first()), $sweep->all());

        $bins = $this->calibration($rows, $score);
        $this->newLine();
        $this->info('Calibration: does a probability of p mean "useful" about p of the time?');
        $this->table(['bin', 'pages', 'mean p', 'actually useful'], $bins->all());

        $path = 'runs/'.now()->format('Y-m-d-His')."-sweep-{$signal}.json";
        Storage::put($path, json_encode(['signal' => $signal, 'labelled' => $rows->count(), 'useful' => $useful, 'sweep' => $sweep, 'calibration' => $bins], JSON_PRETTY_PRINT));
        $this->comment('Saved: '.Storage::path($path));

        // Pages the labellers split on: where does Jev put them?
        $ambiguous = SearchJudgement::query()->where('kind', 'collected')->whereNotNull('panel')->whereNull('label')->get();

        if ($ambiguous->isNotEmpty()) {
            $this->newLine();
            $this->info(sprintf(
                '%d ambiguous pages (labellers disagreed): %s mean %.2f, range %.2f–%.2f',
                $ambiguous->count(), $signal, $ambiguous->avg($score), $ambiguous->min($score), $ambiguous->max($score),
            ));
        }

        return self::SUCCESS;
    }

    private function calibration(Collection $rows, callable $score): Collection
    {
        return collect([[0, 0.2], [0.2, 0.4], [0.4, 0.6], [0.6, 0.8], [0.8, 1.01]])->map(function (array $bin) use ($rows, $score) {
            $in = $rows->filter(fn ($r) => $score($r) >= $bin[0] && $score($r) < $bin[1]);

            return [
                'bin' => sprintf('%.1f–%.1f', $bin[0], min($bin[1], 1)),
                'pages' => $in->count(),
                'mean p' => $in->isEmpty() ? '-' : round($in->avg(fn ($r) => $score($r)), 2),
                'actually useful' => $in->isEmpty() ? '-' : round($in->where('label', true)->count() / $in->count(), 2),
            ];
        });
    }
}
