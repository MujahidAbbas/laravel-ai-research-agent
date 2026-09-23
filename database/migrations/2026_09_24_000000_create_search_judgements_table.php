<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per page Jev judged. Every probability is kept so a threshold
        // can be re-chosen later without calling the API again.
        Schema::create('search_judgements', function (Blueprint $table) {
            $table->id();
            $table->string('run_key', 26)->nullable()->index();
            $table->string('topic');
            $table->string('query');
            $table->string('url', 2048);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('position')->nullable();
            $table->unsignedInteger('content_chars');
            $table->unsignedInteger('judged_chars');
            $table->longText('excerpt'); // exactly what Jev read, so a human labels the same text
            $table->float('relevant')->nullable();
            $table->float('has_evidence')->nullable();
            $table->float('injection')->nullable();
            $table->string('verdict', 10); // keep | brief | drop | unjudged
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('ms')->nullable();
            $table->text('error')->nullable();
            $table->boolean('label')->nullable(); // a human's "useful for this topic", set by ai:label-judgements
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_judgements');
    }
};
