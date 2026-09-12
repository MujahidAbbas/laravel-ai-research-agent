<?php

declare(strict_types=1);

namespace App\Ai\History;

use Laravel\Ai\Messages\Message;

/**
 * Decides what part of a remembered conversation the next turn actually sends.
 *
 * The SDK's RemembersConversations::messages() loads the last 100 rows and
 * sends all of them, tool results included. Overriding messages() on the
 * agent is the one hook the SDK gives you, and it replaces the history
 * wholesale, so the policy receives everything the store returned and hands
 * back what should reach the model.
 */
interface HistoryPolicy
{
    /**
     * @param  Message[]  $messages  the history as the conversation store rehydrated it, oldest first
     * @return Message[]
     */
    public function apply(array $messages, string $conversationId): array;

    /**
     * A short label for run files and tables.
     */
    public function name(): string;
}
