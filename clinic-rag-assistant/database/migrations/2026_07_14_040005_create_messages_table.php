<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// messages: セッション内の発話(user 質問 / assistant 回答)。主キーは ULID。
// assistant メッセージの id が API 上の answer_id に相当する。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->constrained('sessions')->cascadeOnDelete();
            // user / assistant
            $table->string('role', 20);
            $table->text('content');
            // 回答生成のレイテンシ(assistant のみ)
            $table->integer('latency_ms')->nullable();
            // キャッシュ由来の回答か
            $table->boolean('cached')->default(false);
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
