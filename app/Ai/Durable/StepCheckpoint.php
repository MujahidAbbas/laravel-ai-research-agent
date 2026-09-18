<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Messages\Message;

/**
 * Persists the message history the loop is about to send, once per step.
 *
 * StartingStep::$messages is the full replayable transcript so far, including
 * the tool results of every earlier step. Store it and a retry can hand it
 * back through messages() instead of starting from the prompt again.
 *
 * This is also where a stop request becomes a clean exit: between steps, before
 * the next provider call, not in the middle of one.
 */
class StepCheckpoint
{
    public function starting(StartingStep $event): void
    {
        $runKey = CurrentRun::key();

        if ($runKey === null) {
            return;
        }

        // Checkpoint first, so the tool results of the step that just finished
        // are on disk, then stop if the worker was asked to.
        DB::table('agent_step_checkpoints')->updateOrInsert(
            ['run_key' => $runKey, 'step' => $event->stepNumber + 1],
            [
                'message_count' => count($event->messages),
                'messages' => base64_encode(serialize($event->messages)),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        if (CurrentRun::stopRequested()) {
            throw RunInterrupted::beforeStep($event->stepNumber + 1, SIGTERM);
        }
    }

    /**
     * The latest checkpoint for a run, or null when the run never started a step.
     *
     * @return array{step: int, messages: Message[]}|null
     */
    public static function latest(string $runKey): ?array
    {
        // Step numbers restart at 1 on every attempt, so the newest checkpoint is
        // the one with the most history, not the highest step.
        $row = DB::table('agent_step_checkpoints')
            ->where('run_key', $runKey)
            ->orderByDesc('message_count')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return ['step' => (int) $row->step, 'messages' => unserialize(base64_decode($row->messages))];
    }
}
