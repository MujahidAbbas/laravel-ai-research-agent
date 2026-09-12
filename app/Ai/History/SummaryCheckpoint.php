<?php

declare(strict_types=1);

namespace App\Ai\History;

use App\Ai\Agents\ConversationSummarizer;
use App\Models\ConversationSummary;
use Laravel\Ai\Agents\SummarizeAgent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\Usage;

/**
 * When the history outgrows the budget, fold the oldest part into a summary
 * once, persist it, and send summary + the recent tail from then on.
 *
 * The SDK ships the summariser: SummarizeAgent, also reachable as
 * Str::of($text)->summarize(). The class form is used here so the
 * summary call's own usage can be read back and charged to the run.
 *
 * The checkpoint is the point: summarising on every turn would pay for the
 * prefix twice (once to summarise it, once more next turn). This re-summarises
 * only when the un-summarised tail overflows again, and then it extends the
 * existing summary rather than starting over.
 *
 * $coversMessages is an offset into the loaded history, which is stable only
 * while the conversation is under the store's 100-row window. Past that, the
 * rows the summary covers have already fallen off the front, and the offset
 * would need to be a row id instead. Good enough for the measured runs; said
 * plainly in the post.
 */
final class SummaryCheckpoint implements HistoryPolicy
{
    public ?Usage $lastSummaryUsage = null;

    public function __construct(
        public int $budgetTokens = 8000,
        public int $sentences = 5,
        public Lab|string|null $provider = null,
        public ?string $model = null,
        public bool $preserving = false,
    ) {}

    public function apply(array $messages, string $conversationId): array
    {
        $this->lastSummaryUsage = null;

        $stored = ConversationSummary::query()->where('conversation_id', $conversationId)->first();
        $covers = $stored?->covers_messages ?? 0;

        $tail = array_slice($messages, $covers);
        $summaryMessage = $stored ? [$this->summaryMessage($stored->summary)] : [];

        if (Tokens::estimateAll([...$summaryMessage, ...$tail]) <= $this->budgetTokens) {
            return [...$summaryMessage, ...$tail];
        }

        // Overflow: keep what fits in the budget, fold the rest into the summary.
        $window = (new TokenWindow($this->budgetTokens))->apply($tail, $conversationId);
        $fold = array_slice($tail, 0, count($tail) - count($window));

        if ($fold === []) {
            return [...$summaryMessage, ...$window];
        }

        $text = ($stored ? "Summary so far:\n{$stored->summary}\n\n" : '')
            ."Transcript to fold into the summary:\n".Transcript::of($fold);

        // The SDK's SummarizeAgent is the obvious choice and the measured runs
        // showed it dropping every URL and number from earlier folds. The
        // preserving variant is the same shape with instructions that say
        // what to keep.
        $summarizer = $this->preserving ? new ConversationSummarizer : new SummarizeAgent($this->sentences);

        $response = $summarizer->prompt($text, provider: $this->provider, model: $this->model);

        $this->lastSummaryUsage = $response->usage;

        ConversationSummary::query()->updateOrCreate(
            ['conversation_id' => $conversationId],
            [
                'covers_messages' => $covers + count($fold),
                'summary' => $response->text,
                'usage' => $response->usage,
            ],
        );

        return [$this->summaryMessage($response->text), ...$window];
    }

    public function name(): string
    {
        return ($this->preserving ? 'summary2-' : 'summary-').$this->budgetTokens;
    }

    private function summaryMessage(string $summary): Message
    {
        // The SDK keeps instructions separate from history and has no system
        // role in the store, so the summary travels as a user-role message.
        return new Message('user', "Summary of the earlier part of this conversation:\n".$summary);
    }
}
