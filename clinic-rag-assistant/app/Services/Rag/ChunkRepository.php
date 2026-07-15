<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\DB;

// pgvector によるチャンク検索を担うリポジトリ(docs/04 §4)。
// 検索クエリを1箇所に隔離することで、将来の専用ベクトルDB移行の境界にもなる(ADR-001)。
class ChunkRepository
{
    /**
     * クエリ埋め込みでコサイン類似度上位k件を取得する。
     * 類似度しきい値未満のチャンクはアプリ層で除外する。
     *
     * @param  list<float>  $queryEmbedding
     * @return list<array{chunk_id:int, content:string, section_path:string, document_title:string, score:float}>
     */
    public function search(array $queryEmbedding, int $k, float $threshold): array
    {
        $vectorLiteral = '['.implode(',', $queryEmbedding).']';

        // <=> はコサイン距離。1 - 距離 を類似度スコアとする。
        $rows = DB::select(
            <<<'SQL'
            SELECT c.id AS chunk_id,
                   c.content,
                   c.section_path,
                   d.title AS document_title,
                   1 - (c.embedding <=> ?::vector) AS score
            FROM chunks c
            JOIN documents d ON d.id = c.document_id AND d.deleted_at IS NULL
            WHERE c.is_active = true
            ORDER BY c.embedding <=> ?::vector
            LIMIT ?
            SQL,
            [$vectorLiteral, $vectorLiteral, $k]
        );

        $results = [];
        foreach ($rows as $row) {
            $score = (float) $row->score;
            if ($score < $threshold) {
                continue; // no_answer 判定(docs/02 §2.1）
            }
            $results[] = [
                'chunk_id' => (int) $row->chunk_id,
                'content' => (string) $row->content,
                'section_path' => (string) $row->section_path,
                'document_title' => (string) $row->document_title,
                'score' => $score,
            ];
        }

        return $results;
    }
}
