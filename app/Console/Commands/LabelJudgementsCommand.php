<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SearchJudgement;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

/**
 * Label judged pages by hand: was this page useful for the research topic?
 * Jev's probabilities are hidden on purpose, so they can't anchor the label.
 */
class LabelJudgementsCommand extends Command
{
    protected $signature = 'ai:label-judgements {--preview=900 : Characters of page content to show}';

    protected $description = 'Label stored search judgements y/n without seeing Jev\'s probabilities';

    public function handle(): int
    {
        $pending = SearchJudgement::query()->whereNull('label')->whereNotNull('relevant')->orderBy('id');
        $total = $pending->count();
        $done = 0;

        foreach ($pending->lazyById() as $row) {
            $done++;
            $this->newLine();
            $this->line("<fg=gray>[{$done}/{$total}]</> <options=bold>Topic:</> {$row->topic}  <fg=gray>(searched: {$row->query})</>");
            $this->line("<options=bold>{$row->title}</>");
            $this->line("<fg=cyan>{$row->url}</>");
            $this->line('<fg=gray>'.Str::limit((string) $row->description, 300).'</>');
            $shown = (int) $this->option('preview');
            $this->line(Str::limit($row->excerpt, $shown));

            while (($answer = select('Useful for researching this topic?', [
                'y' => 'Yes — I would want the agent to read this',
                'n' => 'No — skip it',
                'm' => 'Show more of the page',
                's' => 'Skip for now',
                'q' => 'Quit',
            ])) === 'm') {
                $this->line(mb_substr($row->excerpt, $shown, 3_000));
                $shown += 3_000;
            }

            if ($answer === 'q') {
                break;
            }

            if ($answer !== 's') {
                $row->update(['label' => $answer === 'y']);
            }
        }

        $this->info(SearchJudgement::whereNotNull('label')->count().' labelled so far.');

        return self::SUCCESS;
    }
}
