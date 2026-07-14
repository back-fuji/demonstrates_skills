<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// documents: 原文(Markdown)とその取り込みステータス。version は楽観ロック用(ADR-005)。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 255);
            // procedure / policy / faq / manual / rule
            $table->string('category', 50);
            $table->text('content');
            // processing / completed / failed
            $table->string('status', 20)->default('processing');
            $table->text('error_message')->nullable();
            // 楽観ロック用バージョン(ADR-005)
            $table->integer('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
