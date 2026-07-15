<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// chunks: 文書を見出し単位で分割した検索単位(ADR-002)。embedding は pgvector の vector 型。
return new class extends Migration
{
    public function up(): void
    {
        // vector 型は Laravel スキーマビルダに無いため、標準カラムを作ってから raw で追加する。
        Schema::create('chunks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            // 見出し階層(例: "予約ポリシー > 当日キャンセル")
            $table->string('section_path', 500);
            $table->text('content');
            $table->integer('token_count');
            // 文書更新時に旧チャンクを false 化(検索対象から除外)
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
        });

        // embedding カラム(次元数は config('rag.embedding.dimensions') と一致させる)
        $dimensions = (int) config('rag.embedding.dimensions', 1024);
        DB::statement("ALTER TABLE chunks ADD COLUMN embedding vector({$dimensions})");

        // HNSW インデックス(ADR-003。Phase 3 で IVFFlat / なし と比較計測する)
        DB::statement(
            'CREATE INDEX chunks_embedding_hnsw ON chunks '
            .'USING hnsw (embedding vector_cosine_ops) '
            .'WITH (m = 16, ef_construction = 64)'
        );

        // 検索は常に is_active = true 条件が付くため部分インデックスを張る
        DB::statement(
            'CREATE INDEX chunks_active_document ON chunks (document_id) WHERE is_active = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
