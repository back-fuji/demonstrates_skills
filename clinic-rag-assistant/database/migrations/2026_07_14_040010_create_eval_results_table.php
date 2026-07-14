<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// eval_results: 各ケースの結果(検索ヒット / Judge 採点 / 回答本文)。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eval_results', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('eval_run_id')->constrained('eval_runs')->cascadeOnDelete();
            $table->foreignId('eval_case_id')->constrained('eval_cases')->cascadeOnDelete();
            // 実際に検索されたチャンクの "文書タイトル#セクション" キー列
            $table->jsonb('retrieved_chunk_keys')->nullable();
            // Recall@k(正解チャンクが上位k件に含まれたか)
            $table->boolean('recall_hit')->nullable();
            // Judge スコア(1-5)
            $table->integer('judge_score')->nullable();
            // 忠実性スコア(1-5)。3未満は0件であるべき(docs/05)
            $table->integer('faithfulness_score')->nullable();
            $table->text('judge_reason')->nullable();
            $table->jsonb('missing_points')->nullable();
            $table->jsonb('hallucinations')->nullable();
            $table->text('answer')->nullable();
            $table->integer('latency_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_results');
    }
};
