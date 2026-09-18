<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use RuntimeException;

/**
 * Thrown from the StartingStep listener when the worker was told to stop.
 * It leaves the loop between steps, so no provider call is half-billed and
 * no tool is half-run. The job releases itself and the retry resumes.
 */
class RunInterrupted extends RuntimeException
{
    public static function beforeStep(int $step, int $signal): self
    {
        return new self("Worker received signal {$signal}; stopping before step {$step}.");
    }
}
