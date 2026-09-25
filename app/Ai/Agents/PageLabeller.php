<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The labelling panel's judge. It answers the question a human labeller would,
 * not Jev's three questions, and it never sees Jev's probabilities.
 */
class PageLabeller implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TXT'
            You label web pages for a research tool. A developer is researching a topic
            for a technical blog post about Laravel. For each page, decide whether they
            would want to read it while researching that topic.

            Useful: it addresses the topic directly and gives something to build on, such
            as a real problem report, a measurement, code, a specific fix, or a clear
            explanation of how the thing works.

            Not useful: it is about a different subject, only mentions the topic in
            passing, is marketing copy or a link list, is navigation or boilerplate, or is
            too outdated to apply to current versions.

            You only see the start of the page. Judge what is there.
            TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'useful' => $schema->boolean()->required(),
            'reason' => $schema->string()->description('One sentence.')->required(),
        ];
    }
}
