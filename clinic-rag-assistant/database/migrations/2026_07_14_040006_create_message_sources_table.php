<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// message_sources: assistant 回答が引用したチャンク(監査証跡・評価ハーネスの分析素材)。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUlid('message_id')->constrained('messages')->cascadeOnDelete();
            // 引用元チャンク。文書更新で物理削除されうるため参照を残しつつ nullOnDelete。
            $table->foreignId('chunk_id')->nullable()->constrained('chunks')->nullOnDelete();
            // コサイン類似度スコア
            $table->float('score');
            // 検索結果内での順位(1 始まり)
            $table->integer('rank');

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_sources');
    }
};
