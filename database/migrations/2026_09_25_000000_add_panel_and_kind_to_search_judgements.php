<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_judgements', function (Blueprint $table) {
            // collected | topic_swap | planted_injection. Controls have a known answer by construction.
            $table->string('kind', 20)->default('collected')->index();
            $table->foreignId('source_id')->nullable(); // the collected row a control was built from
            // Each panel model's verdict and reason. `label` holds the consensus, null when they disagree.
            $table->json('panel')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('search_judgements', function (Blueprint $table) {
            $table->dropColumn(['kind', 'source_id', 'panel']);
        });
    }
};
