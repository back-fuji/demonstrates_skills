<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 会話セッション(ドメイン)。テーブル名は sessions だが Session ファサードと区別するためクラス名は ChatSession(ADR-006)。
class ChatSession extends Model
{
    use HasUlids;

    protected $table = 'sessions';

    protected $fillable = [
        'user_id',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'session_id')->orderBy('created_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
