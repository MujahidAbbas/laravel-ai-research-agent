<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Post;
use Illuminate\Database\Seeder;

/**
 * The author's eight real published Laravel + AI posts. The research agent
 * queries this table (read-only) to find which angles are already covered.
 */
class PostSeeder extends Seeder
{
    public function run(): void
    {
        $posts = [
            ['Testing AI Features in Laravel: The Most Underrated Part of the SDK', 'testing-ai-features-laravel', 'What to actually test in Laravel AI features — provider fakes, tool calls, structured output.', 'laravel,ai,testing,tools', '2026-02-17'],
            ['Laravel AI SDK + Livewire: Build a Streaming Chat Interface', 'laravel-ai-sdk-livewire-chat', 'A streaming AI chat in Livewire using the official Laravel AI SDK instead of the raw OpenAI client.', 'laravel,ai,livewire,streaming', '2026-02-18'],
            ['Evals in Laravel: How to Prove Your AI Output Is Actually Good', 'laravel-ai-evals', 'Tests prove the code runs; evals prove the output is good. How to score AI output in Laravel.', 'laravel,ai,evals,testing', '2026-05-29'],
            ['Multi-Agent Orchestration in Laravel: When You Actually Need It', 'laravel-multi-agent-orchestration', 'The Laravel AI SDK lets one agent delegate to another. Most teams reach for it too early.', 'laravel,ai,agents,orchestration', '2026-05-30'],
            ['Prompt-Injection Guardrails in Laravel: Defend the Tools, Not the Prompt', 'laravel-prompt-injection-guardrails', "You can't out-prompt an attacker. Defend the tools an agent can call, not the system prompt.", 'laravel,ai,security,prompt-injection,tools', '2026-05-30'],
            ['Evaluating RAG in Laravel: Is Your Agent Retrieving the Right Chunks?', 'evaluating-rag-laravel', 'Your RAG demo answers fine. That does not prove retrieval is right. How to evaluate it.', 'laravel,ai,rag,evals', '2026-05-31'],
            ['Claude Code Dynamic Workflows: Audit a Laravel App Without Losing the Thread', 'laravel-dynamic-workflows-claude-code', 'Use the Claude Code Workflow tool to audit a whole Laravel app with deterministic orchestration.', 'laravel,ai,agents,workflows,claude-code', '2026-06-02'],
            ['Building RAG in Laravel: Four Ingestion Bugs That Silently Wreck Retrieval', 'building-rag-laravel-pgvector', 'Every Laravel RAG tutorial builds the same ingestion pipeline and never checks if it works.', 'laravel,ai,rag,pgvector,eloquent', '2026-06-08'],
        ];

        foreach ($posts as [$title, $slug, $description, $tags, $publishedAt]) {
            Post::updateOrCreate(['slug' => $slug], [
                'title' => $title,
                'description' => $description,
                'tags' => $tags,
                'published_at' => $publishedAt,
            ]);
        }
    }
}
