<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// ゴールデンデータセットの1ケース。
class EvalCase extends Model
{
    public const CATEGORY_FACT = 'fact';
    public const CATEGORY_POLICY = 'policy';
    public const CATEGORY_COMPARISON = 'comparison';
    public const CATEGORY_NO_ANSWER = 'no_answer';

    protected $fillable = [
        'key',
        'question',
        'expected_chunk_keys',
        'expected_answer_points',
        'category',
        'is_active',
    ];

    protected $casts = [
        'expected_chunk_keys' => 'array',
        'expected_answer_points' => 'array',
        'is_active' => 'boolean',
    ];

    public function isNoAnswer(): bool
    {
        return $this->category === self::CATEGORY_NO_ANSWER;
    }
}
