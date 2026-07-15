<?php

namespace App\Services\Contracts;

// LLM(回答生成 / Judge採点)の抽象。実API実装とフェイク実装を差し替え可能にする。
interface LlmClient
{
    /**
     * システムプロンプトとユーザープロンプトから回答を生成する。
     * $onToken を渡すとトークン(またはチャンク)ごとにコールバックされ、
     * ストリーミング表示に利用できる。戻り値は生成された全文。
     *
     * @param  callable(string):void|null  $onToken
     */
    public function generate(string $system, string $userPrompt, ?callable $onToken = null): string;

    // 実際に呼び出すモデル識別子(評価ハーネスの記録用)。
    public function model(): string;
}
