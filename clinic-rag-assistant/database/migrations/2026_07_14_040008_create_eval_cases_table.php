<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// eval_cases: ゴールデンデータセット(質問と期待結果のペア)。YAML がマスタ、eval:seed で投入。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eval_cases', function (Blueprint $table) {
            $table->bigIncrements('id');
            // YAML ファイル名由来の安定キー(例: policy_001)
            $table->string('key', 100)->unique();
            $table->text('question');
            // 正解チャンクの指定(環境非依存の "文書タイトル#セクション" 形式)
            $table->jsonb('expected_chunk_keys');
            // 回答に含まれるべき要点(Judge 採点用)
            $table->jsonb('expected_answer_points');
            // fact / policy / comparison / no_answer
            $table->string('category', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_cases');
    }
};
