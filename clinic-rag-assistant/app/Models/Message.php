<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// セッション内の発話。assistant メッセージの id が API 上の answer_id。
class Message extends Model
{
    use HasUlids;

    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'latency_ms',
        'cached',
    ];

    protected $casts = [
        'cached' => 'boolean',
        'latency_ms' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    // 引用元(検索順)
    public function sources(): HasMany
    {
        return $this->hasMany(MessageSource::class)->orderBy('rank');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }
}
