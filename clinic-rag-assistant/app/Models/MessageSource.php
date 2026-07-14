<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 回答が引用したチャンク(監査証跡)。タイムスタンプは持たない。
class MessageSource extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'chunk_id',
        'score',
        'rank',
    ];

    protected $casts = [
        'score' => 'float',
        'rank' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(Chunk::class);
    }
}
