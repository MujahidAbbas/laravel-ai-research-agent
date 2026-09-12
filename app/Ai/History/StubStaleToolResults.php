<?php

declare(strict_types=1);

namespace App\Ai\History;

use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Keep every message, but replace the body of tool results older than the
 * last N turns with a one-line stub.
 *
 * The call stays (same id, same name, same arguments) so the pairing with
 * the provider's tool_use block survives and the model still knows the tool
 * ran. Only the payload goes. This is Anthropic's "tool result clearing",
 * done in the app because the SDK re-sends the stored payload verbatim.
 *
 * A "turn" boundary is a user message in the stored history; the current
 * prompt is appended by the SDK after this policy runs, so keepRecentTurns
 * = 1 keeps the previous turn's results intact and stubs everything older.
 */
final class StubStaleToolResults implements HistoryPolicy
{
    public function __construct(public int $keepRecentTurns = 1) {}

    public function apply(array $messages, string $conversationId): array
    {
        $turnsBack = 0;
        $out = $messages;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if ($this->isUserTurn($message)) {
                $turnsBack++;

                continue;
            }

            if ($turnsBack >= $this->keepRecentTurns && $message instanceof ToolResultMessage) {
                $out[$i] = new ToolResultMessage(
                    $message->toolResults->map(fn (ToolResult $result) => $this->stub($result))
                );
            }
        }

        return $out;
    }

    public function name(): string
    {
        return "stub-keep-{$this->keepRecentTurns}";
    }

    private function isUserTurn(Message $message): bool
    {
        return $message->role === MessageRole::User && ! $message instanceof ToolResultMessage;
    }

    private function stub(ToolResult $result): ToolResult
    {
        return new ToolResult(
            id: $result->id,
            name: $result->name,
            arguments: $result->arguments,
            result: sprintf(
                '[cleared from context] %s(%s) ran earlier in this conversation. Its full output is no longer included; call the tool again if you need the details.',
                $result->name,
                json_encode($result->arguments, JSON_UNESCAPED_SLASHES),
            ),
            resultId: $result->resultId,
            denied: $result->denied,
            failed: $result->failed,
        );
    }
}
