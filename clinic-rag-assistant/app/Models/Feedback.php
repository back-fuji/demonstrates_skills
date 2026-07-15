<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 回答へのフィードバック(👍/👎)。
class Feedback extends Model
{
    public const RATING_GOOD = 'good';
    public const RATING_BAD = 'bad';

    protected $table = 'feedback';

    protected $fillable = [
        'message_id',
        'user_id',
        'rating',
        'comment',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
