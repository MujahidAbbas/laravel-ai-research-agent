<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use ReflectionClass;
use Throwable;

/**
 * Print the model every agent actually sends, before the invoice says so.
 *
 * Model selection is a precedence chain with four inputs and no diagnostics:
 * a call-time argument, a model() method, #[Model], then the cheapest/smartest
 * attributes, then the provider's own default. Every mistake in it is silent.
 *
 * This resolves each agent for real - fake gateway, so no tokens are spent -
 * and reads the model back off the response meta, which is what the request
 * was actually built with.
 */
class RouteMatrixCommand extends Command
{
    protected $signature = 'ai:route-matrix
        {agent?* : Agent classes to resolve (defaults to every agent in app/Ai/Agents)}
        {--failover= : Comma-separated providers to also resolve against, e.g. anthropic,openai}';

    protected $description = 'Print the provider and model each agent resolves to, without spending a token';

    public function handle(): int
    {
        $agents = $this->argument('agent') ?: $this->discoverAgents();

        if ($agents === []) {
            $this->components->error('No agents found. Pass a class name.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf('  <fg=gray>config ai.default =</> %s', config('ai.default')));
        $this->newLine();

        $rows = [];

        foreach ($agents as $class) {
            try {
                $rows[] = $this->resolve($class);
            } catch (Throwable $e) {
                $rows[] = [class_basename($class), 'ERROR', substr($e->getMessage(), 0, 40), ''];
            }
        }

        $this->table(['Agent', 'Provider / model', 'Decided by', 'Ignored'], $rows);

        if ($providers = $this->failoverProviders()) {
            $this->newLine();
            $this->line(sprintf('  <fg=gray>with failover list [%s]:</>', implode(', ', $providers)));
            $this->newLine();

            $rows = [];

            foreach ($agents as $class) {
                try {
                    $rows[] = $this->resolve($class, $providers);
                } catch (Throwable $e) {
                    $rows[] = [class_basename($class), 'ERROR', substr($e->getMessage(), 0, 40), ''];
                }
            }

            $this->table(['Agent', 'Provider / model', 'Decided by', 'Ignored'], $rows);
            $this->comment('  A failover LIST discards your pinned model, including a call-time model: argument.');
            $this->comment('  Use a map to keep it:');
            $this->comment("  prompt(provider: ['anthropic' => 'claude-sonnet-4-6', 'openai' => 'gpt-5.6-terra'])");
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Resolve one agent for real and report what the request was built with.
     *
     * @param  string[]  $failover
     * @return array{string, string, string, string}
     */
    private function resolve(string $class, array $failover = []): array
    {
        Ai::fakeAgent($class, ['ok']);

        $agent = app($class);

        $response = $failover === []
            ? $agent->prompt('.')
            : $agent->prompt('.', provider: $failover);

        [$decidedBy, $ignored] = $this->explain($class, $failover !== []);

        return [
            class_basename($class),
            sprintf('%s / %s', $response->meta->provider, $response->meta->model),
            $decidedBy,
            $ignored,
        ];
    }

    /**
     * Work out which input won, in the SDK's own order.
     *
     * @return array{string, string}
     */
    private function explain(string $class, bool $isFailoverList): array
    {
        $reflection = new ReflectionClass($class);

        $hasModelMethod = method_exists($class, 'model');
        $hasModelAttribute = $reflection->getAttributes(ModelAttribute::class) !== [];
        $hasCheapest = $reflection->getAttributes(UseCheapestModel::class) !== [];
        $hasSmartest = $reflection->getAttributes(UseSmartestModel::class) !== [];

        // A provider LIST discards the model, whatever named it: the guard is
        // `! is_array($provider) && is_null($model)`, and formatProviderAndModelList()
        // maps a numerically-keyed list to [provider => null]. A call-time model:
        // argument goes the same way. The cheapest/smartest attributes survive,
        // because getDefaultModelFor() runs later, per provider.
        if ($isFailoverList) {
            $dropped = array_filter([
                $hasModelMethod ? 'model()' : null,
                $hasModelAttribute ? '#[Model]' : null,
            ]);

            if ($hasSmartest) {
                return ['#[UseSmartestModel]', $this->droppedBy($dropped)];
            }

            if ($hasCheapest) {
                return ['#[UseCheapestModel]', $this->droppedBy($dropped)];
            }

            return ['provider default', $this->droppedBy($dropped)];
        }

        if ($hasModelMethod) {
            return ['model() method', $this->losers($hasModelAttribute ? ['#[Model]'] : [], $hasSmartest, $hasCheapest)];
        }

        if ($hasModelAttribute) {
            return ['#[Model]', $this->losers([], $hasSmartest, $hasCheapest)];
        }

        if ($hasSmartest) {
            return ['#[UseSmartestModel]', $hasCheapest ? '#[UseCheapestModel]' : ''];
        }

        if ($hasCheapest) {
            return ['#[UseCheapestModel]', ''];
        }

        return ['provider default', ''];
    }

    /**
     * @param  string[]  $dropped
     */
    private function droppedBy(array $dropped): string
    {
        return $dropped === [] ? '' : implode(' ', $dropped).' (dropped by the list)';
    }

    /**
     * @param  string[]  $extra
     */
    private function losers(array $extra, bool $hasSmartest, bool $hasCheapest): string
    {
        return implode(' ', array_filter([
            ...$extra,
            $hasSmartest ? '#[UseSmartestModel]' : null,
            $hasCheapest ? '#[UseCheapestModel]' : null,
        ]));
    }

    /**
     * @return string[]
     */
    private function failoverProviders(): array
    {
        $option = (string) ($this->option('failover') ?? '');

        return array_values(array_filter(array_map(
            fn (string $name): string => trim($name),
            explode(',', $option)
        )));
    }

    /**
     * @return string[]
     */
    private function discoverAgents(): array
    {
        $found = [];

        foreach (glob(app_path('Ai/Agents/*.php')) ?: [] as $path) {
            $class = 'App\\Ai\\Agents\\'.basename($path, '.php');

            if (is_a($class, Agent::class, true)) {
                $found[] = $class;
            }
        }

        return $found;
    }
}
