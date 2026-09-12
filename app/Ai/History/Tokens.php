<?php

declare(strict_types=1);

namespace App\Ai\History;

use Laravel\Ai\Messages\Message;

/**
 * chars/4, the same estimate StepUsageRecorder uses. The SDK ships no token
 * counter, and for a budget decision an estimate that is 10-20% off is fine
 * as long as the run file shows how far off it was against promptTokens.
 */
final class Tokens
{
    public static function estimate(Message $message): int
    {
        $encoded = json_encode($message) ?: serialize($message);

        return intdiv(strlen($encoded), 4);
    }

    /**
     * @param  Message[]  $messages
     */
    public static function estimateAll(array $messages): int
    {
        return array_sum(array_map(self::estimate(...), $messages));
    }
}
