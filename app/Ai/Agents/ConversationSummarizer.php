<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * A summariser written for conversation checkpoints, replacing the SDK's
 * generic SummarizeAgent after the measured run showed the generic one
 * dropping every URL and number from earlier folds.
 *
 * Same shape as the SDK's (cheapest model, instructions only); the
 * difference is entirely in what it is told to keep.
 */
#[UseCheapestModel]
final class ConversationSummarizer implements Agent
{
    use Promptable;

    public function __construct(public int $maxWords = 300) {}

    public function instructions(): string
    {
        return <<<TXT
            You maintain a running summary of a conversation between a user and a research assistant that uses tools.

            You receive the summary so far (possibly empty) and a transcript of newer messages. Return the updated summary and nothing else.

            Rules:
            - Keep everything already in the summary so far unless the transcript contradicts it. Extend, do not rewrite.
            - Keep every URL, number, percentage, paper id, product name and decision that appears in either input, verbatim.
            - Record which tools were called and with what arguments, in one line each.
            - Record what the user asked for at each step and what the assistant concluded.
            - Plain prose, no headings, no bullet lists, at most {$this->maxWords} words.
            TXT;
    }
}
