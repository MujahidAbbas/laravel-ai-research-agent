<?php

declare(strict_types=1);

namespace App\Ai\Durable;

/**
 * The run key for the agent run this worker process is executing, plus the
 * interrupt flag the job sets when the worker receives a signal.
 *
 * Process-local on purpose: a queue worker runs one job at a time, and the
 * SDK's events carry an invocation id but not the job that invoked them.
 */
final class CurrentRun
{
    private static ?string $key = null;

    private static bool $stopRequested = false;

    public static function begin(string $key): void
    {
        self::$key = $key;
        self::$stopRequested = false;
    }

    public static function key(): ?string
    {
        return self::$key;
    }

    public static function requestStop(): void
    {
        self::$stopRequested = true;
    }

    public static function stopRequested(): bool
    {
        return self::$stopRequested;
    }
}
