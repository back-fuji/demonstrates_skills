<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 検索単位のチャンク。embedding は pgvector の vector 型(取り込みは raw 文字列で保存)。
class Chunk extends Model
{
    // created_at のみで updated_at を持たない
    public const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'section_path',
        'content',
        'token_count',
        'is_active',
        'embedding',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'token_count' => 'integer',
        'created_at' => 'datetime',
    ];

    // embedding(vector 型)は通常の SELECT では取得しない(サイズが大きいため)。
    protected $hidden = ['embedding'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    // 環境非依存の安定キー("文書タイトル#セクション")。評価ハーネスの正解照合に使う。
    public function sourceKey(): string
    {
        $title = $this->document?->title ?? '';

        return $title.'#'.$this->section_path;
    }

    // float 配列を pgvector リテラル('[1,2,3]')へ変換する。
    public static function toVectorLiteral(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }
}
