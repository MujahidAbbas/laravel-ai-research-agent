<?php

namespace Tests\Feature;

use App\Ai\Agents\ConversationSummarizer;
use App\Ai\Agents\DurableResearchAgent;
use App\Ai\Agents\ResearchAgent;
use App\Ai\Agents\RoutedResearchAgent;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\TestCase;

/**
 * Every claim and code block in the model-routing post, on fakes. Nothing here
 * calls a provider: Agent::fake() swaps the gateway, so attribute resolution,
 * failover formatting, step middleware and the events all run for real.
 */
class ModelRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_model_attribute_makes_use_cheapest_model_dead_code(): void
    {
        PinnedResearchAgent::fake(['ok']);

        $response = (new PinnedResearchAgent)->prompt('Summarise this in one line.');

        $this->assertSame('anthropic', $response->meta->provider);
        $this->assertSame('claude-sonnet-4-6', $response->meta->model);
    }

    public function test_a_call_time_model_beats_every_attribute(): void
    {
        PinnedResearchAgent::fake(['ok']);

        $response = (new PinnedResearchAgent)->prompt('.', model: 'claude-haiku-4-5-20251001');

        $this->assertSame('claude-haiku-4-5-20251001', $response->meta->model);
    }

    public function test_a_failover_list_discards_the_pinned_model(): void
    {
        SonnetAgent::fake(['ok']);

        $response = (new SonnetAgent)->prompt('.', provider: [Lab::Anthropic, Lab::OpenAI]);

        $this->assertSame('claude-sonnet-5-5', $response->meta->model);
    }

    public function test_a_failover_list_discards_the_model_typed_in_the_same_call(): void
    {
        SonnetAgent::fake(['ok']);

        $agent = new SonnetAgent;

        $response = $agent->prompt('...', provider: [Lab::Anthropic, Lab::OpenAI], model: 'claude-haiku-4-5-20251001');

        $this->assertSame('claude-sonnet-5-5', $response->meta->model);
    }

    public function test_a_failover_map_keeps_the_model(): void
    {
        SonnetAgent::fake(['ok']);

        $agent = new SonnetAgent;

        $response = $agent->prompt('...', provider: [
            'anthropic' => 'claude-sonnet-4-6',
            'openai' => 'gpt-6.1-sol',
        ]);

        $this->assertSame('claude-sonnet-4-6', $response->meta->model);
    }

    public function test_role_attributes_survive_a_failover_list(): void
    {
        CheapestAgent::fake(['ok']);

        $response = (new CheapestAgent)->prompt('.', provider: [Lab::Anthropic, Lab::OpenAI]);

        $this->assertSame('anthropic', $response->meta->provider);
        $this->assertSame('claude-haiku-5-5', $response->meta->model);
    }

    public function test_a_failover_list_brings_the_dead_cheapest_attribute_back(): void
    {
        PinnedResearchAgent::fake(['ok']);

        $response = (new PinnedResearchAgent)->prompt('.', provider: [Lab::Anthropic, Lab::OpenAI]);

        $this->assertSame('claude-haiku-5-5', $response->meta->model);
    }

    /**
     * The post's test, verbatim.
     */
    public function test_the_answer_step_runs_on_the_cheap_model(): void
    {
        RoutedResearchAgent::fake([
            new ToolCall('call_1', 'DatabaseQueryTool', ['query' => 'select title from posts']),
            new ToolCall('call_2', 'DatabaseQueryTool', ['query' => 'select slug from posts']),
            new ToolCall('call_3', 'DatabaseQueryTool', ['query' => 'select tags from posts']),
            'Three angles.',
        ]);

        $response = (new RoutedResearchAgent)->prompt('Find an angle on model routing.');

        $this->assertSame(
            ['claude-sonnet-4-6', 'claude-sonnet-4-6', 'claude-sonnet-4-6', 'claude-haiku-5-5'],
            $response->steps->map(fn (Step $step) => $step->meta->model)->all(),
        );
    }

    public function test_the_answer_step_is_sent_with_tools_forbidden(): void
    {
        $steps = [];

        Event::listen(StartingStep::class, function (StartingStep $event) use (&$steps) {
            $steps[$event->stepNumber] = [$event->model, $event->options?->toolChoice?->mode];
        });

        RoutedResearchAgent::fake([...$this->threeToolCalls(), 'Three angles.']);

        (new RoutedResearchAgent)->prompt('Find an angle on model routing.');

        $this->assertSame([
            0 => ['claude-sonnet-4-6', null],
            1 => ['claude-sonnet-4-6', null],
            2 => ['claude-sonnet-4-6', null],
            3 => ['claude-haiku-5-5', 'none'],
        ], $steps);
    }

    public function test_a_run_that_answers_within_three_steps_never_reaches_the_cheap_model(): void
    {
        RoutedResearchAgent::fake([
            new ToolCall('call_1', 'DatabaseQueryTool', ['query' => 'select title from posts']),
            new ToolCall('call_2', 'DatabaseQueryTool', ['query' => 'select slug from posts']),
            'Three angles.',
        ]);

        $response = (new RoutedResearchAgent)->prompt('Find an angle on model routing.');

        $this->assertSame(
            ['claude-sonnet-4-6', 'claude-sonnet-4-6', 'claude-sonnet-4-6'],
            $response->steps->map(fn (Step $step) => $step->meta->model)->all(),
        );
    }

    public function test_a_run_held_by_openai_gets_the_openai_cheap_model(): void
    {
        RoutedResearchAgent::fake([...$this->threeToolCalls(), 'Three angles.']);

        $response = (new RoutedResearchAgent)->prompt('Find an angle on model routing.', provider: ['openai' => 'gpt-6.1-sol']);

        $this->assertSame(
            ['gpt-6.1-sol', 'gpt-6.1-sol', 'gpt-6.1-sol', 'gpt-6-luna'],
            $response->steps->map(fn (Step $step) => $step->meta->model)->all(),
        );
    }

    /**
     * The trap: assertPrompted() and PromptingAgent read claude-sonnet-4-6
     * whether the router runs or not, and $response->meta reads only the last
     * step, which a router that sent every step to Haiku would match too.
     */
    public function test_the_run_level_observables_miss_the_router(): void
    {
        $meta = [];

        foreach ([RoutedResearchAgent::class, UnroutedResearchAgent::class, AllCheapResearchAgent::class] as $class) {
            $seen = null;

            Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$seen) {
                $seen = $event->prompt->model;
            });

            $class::fake([...$this->threeToolCalls(), 'Three angles.']);

            $response = (new $class)->prompt('Find an angle on model routing.');

            $class::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'claude-sonnet-4-6');
            $this->assertSame('claude-sonnet-4-6', $seen, $class);
            $meta[$class] = $response->meta->model;

            Event::forget(PromptingAgent::class);
        }

        $this->assertSame('claude-haiku-5-5', $meta[RoutedResearchAgent::class]);
        $this->assertSame('claude-sonnet-4-6', $meta[UnroutedResearchAgent::class]);
        $this->assertSame('claude-haiku-5-5', $meta[AllCheapResearchAgent::class]);
    }

    /**
     * The post's per-step usage loop, verbatim.
     */
    public function test_the_usage_loop_logs_one_line_per_step(): void
    {
        Log::spy();

        RoutedResearchAgent::fake([...$this->threeToolCalls(), 'Three angles.']);

        $response = (new RoutedResearchAgent)->prompt('Find an angle on model routing.');

        foreach ($response->steps as $i => $step) {
            Log::info('agent step', [
                'step' => $i,
                'model' => $step->meta->model,
                'input' => $step->usage->inputTokens,
                'cache_read' => $step->usage->cacheReadInputTokens,
                'cache_write' => $step->usage->cacheWriteInputTokens,
                'output' => $step->usage->outputTokens,
            ]);
        }

        Log::shouldHaveReceived('info')->times(4);
        Log::shouldHaveReceived('info')->with('agent step', \Mockery::on(fn (array $context) => $context['step'] === 3 && $context['model'] === 'claude-haiku-5-5'));
    }

    public function test_route_matrix_reads_step_0_so_the_finish_cheap_router_does_not_show(): void
    {
        $this->artisan('ai:route-matrix', ['agent' => [ConversationSummarizer::class, RoutedResearchAgent::class]])
            ->expectsTable(['Agent', 'Provider / model', 'Decided by', 'Ignored'], [
                ['ConversationSummarizer', 'openai / gpt-6-luna', '#[UseCheapestModel]', ''],
                ['RoutedResearchAgent', 'anthropic / claude-sonnet-4-6', '#[Model]', ''],
            ])
            ->assertSuccessful();
    }

    public function test_route_matrix_names_step_middleware_that_changes_step_0(): void
    {
        $this->artisan('ai:route-matrix', ['agent' => [AllCheapResearchAgent::class]])
            ->expectsTable(['Agent', 'Provider / model', 'Decided by', 'Ignored'], [
                ['AllCheapResearchAgent', 'anthropic / claude-haiku-5-5', 'step middleware (step 0)', '#[Model]'],
            ])
            ->assertSuccessful();
    }

    public function test_route_matrix_names_the_constructor_argument_an_agent_needs(): void
    {
        $this->artisan('ai:route-matrix', ['agent' => [DurableResearchAgent::class, ResearchAgent::class]])
            ->expectsTable(['Agent', 'Provider / model', 'Decided by', 'Ignored'], [
                ['DurableResearchAgent', 'needs constructor argument $runKey', '', ''],
                ['ResearchAgent', 'openai / gpt-6.1-sol', 'provider default', ''],
            ])
            ->assertSuccessful();
    }

    /**
     * @return ToolCall[]
     */
    private function threeToolCalls(): array
    {
        return [
            new ToolCall('call_1', 'DatabaseQueryTool', ['query' => 'select title from posts']),
            new ToolCall('call_2', 'DatabaseQueryTool', ['query' => 'select slug from posts']),
            new ToolCall('call_3', 'DatabaseQueryTool', ['query' => 'select tags from posts']),
        ];
    }

    public function test_a_step_built_with_another_provider_still_goes_to_the_agents_provider(): void
    {
        ProviderSwappingAgent::fake(['ok']);

        $response = (new ProviderSwappingAgent)->prompt('.');

        $this->assertSame('anthropic', $response->meta->provider);
        $this->assertSame('gpt-6.1-sol', $response->meta->model);
    }
}

// The post's example class.
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[UseCheapestModel]
class PinnedResearchAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a content-research assistant.';
    }
}

// The failover section's agent: pinned, no role attribute.
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
class SonnetAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'x';
    }
}

#[UseCheapestModel]
class CheapestAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'x';
    }
}

// RoutedResearchAgent without the router.
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
class UnroutedResearchAgent extends ResearchAgent {}

class EveryStepCheap
{
    public function handle(PendingStep $step, Closure $next)
    {
        return $next($step->withModel('claude-haiku-5-5'));
    }
}

// A broken router: every step on Haiku, tools included.
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
class AllCheapResearchAgent extends ResearchAgent implements HasMiddleware
{
    public function middleware(): array
    {
        return [new EveryStepCheap];
    }
}

class SwapToOpenAi
{
    public function handle(PendingStep $step, Closure $next)
    {
        return $next(new PendingStep(
            $step->number, $step->isFinalStep, 'openai', 'gpt-6.1-sol', $step->instructions,
            $step->messages, $step->tools, $step->schema, $step->options, $step->steps,
            $step->usage, $step->timeout, $step->invocationId,
        ));
    }
}

#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
class ProviderSwappingAgent implements Agent, HasMiddleware
{
    use Promptable;

    public function instructions(): string
    {
        return 'x';
    }

    public function middleware(): array
    {
        return [new SwapToOpenAi];
    }
}
