<?php

declare(strict_types=1);

namespace App\Ai\History;

/**
 * What the SDK does by default: the last 100 rows, verbatim.
 */
final class KeepEverything implements HistoryPolicy
{
    public function apply(array $messages, string $conversationId): array
    {
        return $messages;
    }

    public function name(): string
    {
        return 'all';
    }
}
