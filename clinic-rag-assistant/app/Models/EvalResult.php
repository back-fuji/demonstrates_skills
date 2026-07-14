<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 各ケースの評価結果。
class EvalResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'eval_run_id',
        'eval_case_id',
        'retrieved_chunk_keys',
        'recall_hit',
        'judge_score',
        'faithfulness_score',
        'judge_reason',
        'missing_points',
        'hallucinations',
        'answer',
        'latency_ms',
    ];

    protected $casts = [
        'retrieved_chunk_keys' => 'array',
        'missing_points' => 'array',
        'hallucinations' => 'array',
        'recall_hit' => 'boolean',
        'judge_score' => 'integer',
        'faithfulness_score' => 'integer',
        'latency_ms' => 'integer',
    ];

    public function evalCase(): BelongsTo
    {
        return $this->belongsTo(EvalCase::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvalRun::class, 'eval_run_id');
    }
}
