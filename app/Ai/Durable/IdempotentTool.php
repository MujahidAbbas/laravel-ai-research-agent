<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use App\Ai\QueueRunLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;
use Throwable;

/**
 * Wraps a tool so that, within one run, the same call executes once. The key is
 * the run plus the tool plus a hash of the arguments that make two calls the
 * same side effect, not the provider's tool-call id: a retried job re-plans and
 * the model mints new ids, so the id never matches across attempts.
 *
 * Not every argument belongs in the key. On resume the model re-plans the step
 * that was in flight and rewords free text (the crawl prompt), so a hash of
 * everything it typed misses the match. Pass the stable arguments; with none,
 * the key covers all of them.
 *
 * Claim first, execute second. A crash between the two leaves a 'claimed' row:
 * the outcome is unknown. The retry re-runs it, unless the tool is at-most-once,
 * in which case the model is told it may or may not have run. A crash after the
 * result is stored costs nothing on retry.
 */
class IdempotentTool implements Tool
{
    /**
     * @param  list<string>  $keyArguments
     */
    public function __construct(
        private readonly Tool $tool,
        private readonly string $runKey,
        private readonly array $keyArguments = [],
        private readonly bool $atMostOnce = false,
    ) {}

    public function name(): string
    {
        return ToolNameResolver::resolve($this->tool);
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        $args = $request->all();
        ksort($args);

        $key = $this->keyArguments === [] ? $args : array_intersect_key($args, array_flip($this->keyArguments));
        $sha = sha1(json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $claim = [
            'run_key' => $this->runKey,
            'tool' => $this->name(),
            'args_sha' => $sha,
        ];

        try {
            DB::table('agent_tool_invocations')->insert([
                ...$claim,
                'args' => json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'status' => 'claimed',
                'tool_call_id' => $request->toolCallId(),
                'pid' => getmypid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('agent_tool_invocations')->where($claim)->first();

            if ($existing->status === 'done') {
                QueueRunLog::note('ledger.replayed', ['tool' => $this->name(), 'args_sha' => substr($sha, 0, 8), 'result_chars' => strlen((string) $existing->result), 'claimed_by_pid' => $existing->pid]);

                return $existing->result;
            }

            // 'claimed' with no result: the previous attempt died between claim and
            // store, so the outcome is unknown. For a paid side effect, a second
            // charge is worse than a missing result: tell the model, the way the SDK
            // replays an interrupted call, and do not run it.
            if ($this->atMostOnce && $existing->status === 'claimed') {
                QueueRunLog::note('ledger.unknown', ['tool' => $this->name(), 'args_sha' => substr($sha, 0, 8), 'claimed_by_pid' => $existing->pid]);

                return 'A previous attempt of this run started this call and was interrupted before a result was recorded, so it may or may not have run. Do not call it again.';
            }

            // Anything else is safe to repeat: run it again and take over the row.
            QueueRunLog::note('ledger.reclaimed', ['tool' => $this->name(), 'args_sha' => substr($sha, 0, 8), 'previous_status' => $existing->status, 'claimed_by_pid' => $existing->pid]);
        }

        try {
            $result = (string) $this->tool->handle($request);
        } catch (Throwable $e) {
            DB::table('agent_tool_invocations')->where($claim)->update(['status' => 'failed', 'pid' => getmypid(), 'updated_at' => now()]);

            throw $e;
        }

        DB::table('agent_tool_invocations')->where($claim)->update([
            'status' => 'done',
            'result' => $result,
            'pid' => getmypid(),
            'updated_at' => now(),
        ]);

        return $result;
    }
}
