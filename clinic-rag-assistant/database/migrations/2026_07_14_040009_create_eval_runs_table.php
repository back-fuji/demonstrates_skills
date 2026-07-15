<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// eval_runs: 評価ハーネスの1回の実行(git_sha / prompt_version / model と集計サマリ)。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eval_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('git_sha', 40)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->string('model', 100)->nullable();
            // retrieval / full
            $table->string('mode', 20)->default('full');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // recall@k / judge 平均点 等の集計
            $table->jsonb('summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_runs');
    }
};
