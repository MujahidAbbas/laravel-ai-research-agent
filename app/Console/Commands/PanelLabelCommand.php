<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Agents\PageLabeller;
use App\Models\SearchJudgement;
use Illuminate\Console\Command;
use Throwable;

/**
 * Labels judged pages with two frontier models from different vendors.
 * Where they agree, that is the label; where they disagree, the page is ambiguous.
 */
class PanelLabelCommand extends Command
{
    protected $signature = 'ai:panel-label {--limit= : Label at most this many pages}';

    protected $description = 'Label judged pages with a two-vendor model panel (consensus only)';

    /** @var array<string, string> */
    private const array PANEL = [
        'anthropic' => 'claude-opus-5-5',
        'openai' => 'gpt-6-astra',
    ];

    public function handle(): int
    {
        $rows = SearchJudgement::query()
            ->where('kind', 'collected')
            ->whereNotNull('relevant')
            ->whereNull('panel')
            ->orderBy('id')
            ->when($this->option('limit'), fn ($q, $limit) => $q->limit((int) $limit))
            ->get();

        foreach ($rows as $row) {
            $panel = [];

            foreach (self::PANEL as $provider => $model) {
                try {
                    $response = (new PageLabeller)->prompt($this->prompt($row), provider: $provider, model: $model, timeout: 120);
                } catch (Throwable $e) {
                    $this->warn("#{$row->id} {$provider}: ".$e->getMessage());

                    continue 2;
                }

                $panel[$provider] = [
                    'model' => $model,
                    'useful' => (bool) $response['useful'],
                    'reason' => $response['reason'],
                    'input_tokens' => $response->usage->inputTokens,
                    'output_tokens' => $response->usage->outputTokens,
                ];
            }

            $votes = array_column($panel, 'useful');
            $row->update(['panel' => $panel, 'label' => count(array_unique($votes)) === 1 ? $votes[0] : null]);

            $this->line(sprintf('#%d %s | %s', $row->id, implode(' / ', array_map(fn ($v) => $v ? 'yes' : 'no', $votes)), $row->url));
        }

        $labelled = SearchJudgement::where('kind', 'collected')->whereNotNull('panel');
        $this->info(sprintf(
            '%d panelled, %d agreed, %d ambiguous.',
            $labelled->count(),
            (clone $labelled)->whereNotNull('label')->count(),
            (clone $labelled)->whereNull('label')->count(),
        ));

        return self::SUCCESS;
    }

    private function prompt(SearchJudgement $row): string
    {
        return "Research topic: {$row->topic}\nURL: {$row->url}\nTitle: {$row->title}\n\nStart of the page:\n{$row->excerpt}";
    }
}
