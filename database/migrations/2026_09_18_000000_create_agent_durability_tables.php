<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Claim-before-execute ledger. One row per (run, tool, arguments).
        // The unique index is the lock: whoever inserts first runs the tool.
        Schema::create('agent_tool_invocations', function (Blueprint $table) {
            $table->id();
            $table->string('run_key', 26)->index();
            $table->string('tool', 120);
            $table->string('args_sha', 40);
            $table->json('args');
            $table->string('status', 12)->default('claimed'); // claimed | done | failed
            $table->longText('result')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->timestamps();

            $table->unique(['run_key', 'tool', 'args_sha']);
        });

        // The replayable history as of the start of each step, per run.
        Schema::create('agent_step_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('run_key', 26);
            $table->unsignedSmallInteger('step');
            $table->unsignedSmallInteger('message_count');
            $table->longText('messages'); // base64 of serialize()d Laravel\Ai\Messages\Message[]
            $table->timestamps();

            $table->unique(['run_key', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_step_checkpoints');
        Schema::dropIfExists('agent_tool_invocations');
    }
};
