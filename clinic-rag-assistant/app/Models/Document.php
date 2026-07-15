<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// 原文(Markdown)と取り込みステータスを保持。version は楽観ロック用(ADR-005)。
class Document extends Model
{
    use SoftDeletes;

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'title',
        'category',
        'content',
        'status',
        'error_message',
        'version',
    ];

    protected $casts = [
        'version' => 'integer',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    // 有効な(検索対象の)チャンクのみ
    public function activeChunks(): HasMany
    {
        return $this->chunks()->where('is_active', true);
    }
}
