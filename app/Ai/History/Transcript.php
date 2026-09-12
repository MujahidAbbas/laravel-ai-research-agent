<?php

declare(strict_types=1);

namespace App\Ai\History;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Render messages as plain text for the summariser. Tool results are cut to
 * $resultChars each so the summary call does not itself re-send the payload
 * it exists to get rid of.
 */
final class Transcript
{
    /**
     * @param  Message[]  $messages
     */
    public static function of(array $messages, int $resultChars = 1500): string
    {
        $lines = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    /** @var ToolResult $result */
                    $body = is_string($result->result) ? $result->result : json_encode($result->result, JSON_UNESCAPED_SLASHES);
                    $lines[] = sprintf('tool result %s: %s', $result->name, mb_strimwidth((string) $body, 0, $resultChars, '…'));
                }

                continue;
            }

            if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
                foreach ($message->toolCalls as $call) {
                    /** @var ToolCall $call */
                    $lines[] = sprintf('assistant called %s(%s)', $call->name, json_encode($call->arguments, JSON_UNESCAPED_SLASHES));
                }
            }

            if (filled($message->content)) {
                $lines[] = sprintf('%s: %s', $message->role->value, $message->content);
            }
        }

        return implode("\n", $lines);
    }
}
