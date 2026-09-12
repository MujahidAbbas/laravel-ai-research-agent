<?php

declare(strict_types=1);

namespace App\Ai\History;

use Laravel\Ai\Messages\ToolResultMessage;

/**
 * Keep the newest messages that fit a token budget.
 *
 * Walks the history newest-first so the most recent turn is always kept in
 * full (even when it alone exceeds the budget). The cut can land between an
 * assistant message that made tool calls and the ToolResultMessage that
 * answered them; the newer of the two is the result, so a naive slice starts
 * the history on a tool_result whose tool_use is gone. Anthropic rejects
 * that request. The store's own window guards against it with a skipWhile;
 * a custom messages() has to do it itself, which is what $dropLeadingResults
 * is for. Set it false to see the failure.
 */
final class TokenWindow implements HistoryPolicy
{
    public function __construct(
        public int $budgetTokens = 8000,
        public bool $dropLeadingResults = true,
    ) {}

    public function apply(array $messages, string $conversationId): array
    {
        $kept = [];
        $spent = 0;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $cost = Tokens::estimate($messages[$i]);

            if ($kept !== [] && $spent + $cost > $this->budgetTokens) {
                break;
            }

            $spent += $cost;
            array_unshift($kept, $messages[$i]);
        }

        if ($this->dropLeadingResults) {
            while ($kept !== [] && $kept[0] instanceof ToolResultMessage) {
                array_shift($kept);
            }
        }

        return $kept;
    }

    public function name(): string
    {
        return $this->dropLeadingResults ? "window-{$this->budgetTokens}" : "naive-{$this->budgetTokens}";
    }
}
