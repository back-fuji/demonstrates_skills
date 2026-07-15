<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 評価ハーネスの1回の実行。
class EvalRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'git_sha',
        'prompt_version',
        'model',
        'mode',
        'started_at',
        'finished_at',
        'summary',
    ];

    protected $casts = [
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(EvalResult::class);
    }
}
