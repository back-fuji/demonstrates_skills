<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// feedback: 回答(assistant メッセージ)への 👍/👎。評価ハーネスのゴールデンセット候補収集にも使う。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUlid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // good / bad
            $table->string('rating', 10);
            $table->text('comment')->nullable();
            $table->timestamps();

            // 1メッセージにつき1ユーザー1評価
            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
