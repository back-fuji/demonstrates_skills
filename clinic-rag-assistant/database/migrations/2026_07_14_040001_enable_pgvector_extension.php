<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// pgvector 拡張を有効化する(ADR-001)。以降の chunks.embedding(vector型)の前提。
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
