<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\History\HistoryPolicy;
use App\Ai\History\KeepEverything;
use App\Ai\History\StubStaleToolResults;
use App\Ai\History\Tokens;
use App\Ai\History\TokenWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;

/**
 * Replay a stored conversation through each history policy without calling
 * a model. Live runs differ from each other because every search returns
 * different pages; this holds the conversation fixed and varies only the
 * policy, so the comparison is apples to apples. Estimates are chars/4;
 * the live runs give the billed numbers that calibrate them.
 */
class ReplayCommand extends Command
{
    protected $signature = 'research:replay
        {conversation : A stored conversation id}
        {--budget=30000 : Token budget for the window policies}
        {--keep-results=1 : stub: recent turns that keep their tool results}';

    protected $description = 'Apply each history policy to a stored conversation and estimate what every turn would have sent';

    public function handle(ConversationStore $store): int
    {
        $conversationId = (string) $this->argument('conversation');
        $messages = $store->getLatestConversationMessages($conversationId, 100)->all();

        if ($messages === []) {
            $this->error('No messages for that conversation.');

            return self::FAILURE;
        }

        $policies = [
            new KeepEverything,
            new TokenWindow((int) $this->option('budget')),
            new StubStaleToolResults((int) $this->option('keep-results')),
        ];

        $rows = [];
        $totals = array_fill_keys(array_map(fn (HistoryPolicy $p) => $p->name(), $policies), 0);

        // Turn k sends everything stored before its own user message.
        $turn = 0;
        foreach ($messages as $index => $message) {
            if (! $this->isUserTurn($message)) {
                continue;
            }

            $turn++;
            $history = array_slice($messages, 0, $index);
            $row = ['turn' => $turn, 'messages' => count($history)];

            foreach ($policies as $policy) {
                $sent = $policy->apply($history, $conversationId);
                $estimate = Tokens::estimateAll($sent);
                $toolResults = Tokens::estimateAll(array_values(array_filter($sent, fn (Message $m) => $m instanceof ToolResultMessage)));

                $row[$policy->name()] = ['messages' => count($sent), 'est_tokens' => $estimate, 'tool_result_est_tokens' => $toolResults];
                $totals[$policy->name()] += $estimate;
            }

            $rows[] = $row;
        }

        $this->table(
            ['Turn', 'Stored msgs', ...array_map(fn (HistoryPolicy $p) => $p->name().' (est / tool results)', $policies)],
            array_map(fn (array $r) => [
                $r['turn'],
                $r['messages'],
                ...array_map(fn (HistoryPolicy $p) => number_format($r[$p->name()]['est_tokens']).' / '.number_format($r[$p->name()]['tool_result_est_tokens']), $policies),
            ], $rows),
        );

        foreach ($totals as $name => $total) {
            $this->comment(sprintf('%-14s history re-sent across %d turns: %s est tokens', $name, count($rows), number_format($total)));
        }

        $path = sprintf('runs/%s-replay-%s.json', now()->format('Y-m-d-His'), substr($conversationId, 0, 8));
        Storage::put($path, json_encode([
            'conversation_id' => $conversationId,
            'recorded_at' => now()->toIso8601String(),
            'options' => ['budget' => (int) $this->option('budget'), 'keep_results' => (int) $this->option('keep-results')],
            'turns' => $rows,
            'totals' => $totals,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->comment("Replay saved: ".Storage::path($path));

        return self::SUCCESS;
    }

    private function isUserTurn(Message $message): bool
    {
        return $message->role === MessageRole::User && ! $message instanceof ToolResultMessage;
    }
}
