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
 * Wraps a tool so that, within one run, the same call with the same arguments
 * executes once. The key is the run plus the tool plus a hash of the arguments,
 * not the provider's tool-call id: a retried job re-plans and the model mints
 * new ids, so the id never matches across attempts.
 *
 * Claim first, execute second. A crash between the two leaves a 'claimed' row,
 * which the retry treats as "unknown outcome" and re-runs; a crash after the
 * result is stored costs nothing on retry.
 */
class IdempotentTool implements Tool
{
    public function __construct(
        private readonly Tool $tool,
        private readonly string $runKey,
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
        $sha = sha1(json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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
            // store. The outcome is unknown, so the honest move is to run it again
            // and take over the row.
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
