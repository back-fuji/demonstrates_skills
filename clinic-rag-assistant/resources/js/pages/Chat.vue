<script setup lang="ts">
// チャット画面。質問を送信し、SSE で sources → token → done を受信して表示する。
import { ref } from 'vue';
import {
    searchStream,
    sendFeedback,
    type SourceChunk,
    type SearchDone,
} from '../api/client';

const question = ref('');
const sessionId = ref<string | null>(null);
const streaming = ref(false);

// 現在の回答の表示状態
const sources = ref<SourceChunk[]>([]);
const answer = ref('');
const result = ref<SearchDone | null>(null);
const noAnswer = ref(false);
const errorMsg = ref('');

// 送信済みの質問文(回答の見出しに表示)
const askedQuestion = ref('');

// フィードバック送信状態(answer_id 単位)
const feedbackSent = ref<'good' | 'bad' | null>(null);

async function onSubmit() {
    const q = question.value.trim();
    if (q === '' || streaming.value) return;

    // 表示状態を初期化(session_id はフォローアップのため保持)
    askedQuestion.value = q;
    sources.value = [];
    answer.value = '';
    result.value = null;
    noAnswer.value = false;
    errorMsg.value = '';
    feedbackSent.value = null;
    streaming.value = true;
    question.value = '';

    await searchStream(
        {
            question: q,
            ...(sessionId.value ? { session_id: sessionId.value } : {}),
        },
        {
            onSession: (id) => {
                sessionId.value = id;
            },
            onSources: (chunks) => {
                // 回答生成前に届くので即座に引用元カードを表示
                sources.value = chunks;
            },
            onToken: (text) => {
                answer.value += text;
            },
            onDone: (payload) => {
                result.value = payload;
            },
            onNoAnswer: (payload) => {
                noAnswer.value = true;
                result.value = payload;
            },
            onError: (message) => {
                errorMsg.value = message;
            },
        },
    );

    streaming.value = false;
}

async function onFeedback(rating: 'good' | 'bad') {
    if (!result.value || feedbackSent.value) return;
    try {
        await sendFeedback(result.value.answer_id, rating);
        feedbackSent.value = rating;
    } catch {
        errorMsg.value = 'フィードバックの送信に失敗しました。';
    }
}

// 類似度スコアを見やすく整形
function fmtScore(score: number): string {
    return score.toFixed(2);
}
</script>

<template>
    <div class="container">
        <form class="card ask" @submit.prevent="onSubmit">
            <label for="q">質問を入力してください</label>
            <textarea
                id="q"
                v-model="question"
                rows="2"
                placeholder="例:医療脱毛の当日キャンセル料はいくらですか?"
                @keydown.meta.enter="onSubmit"
            ></textarea>
            <div class="ask-actions">
                <span class="muted" v-if="sessionId">フォローアップ質問として送信されます</span>
                <span v-else></span>
                <button type="submit" :disabled="streaming || question.trim() === ''">
                    {{ streaming ? '検索中…' : '質問する' }}
                </button>
            </div>
        </form>

        <div v-if="errorMsg" class="error" style="margin-top: 1rem">{{ errorMsg }}</div>

        <!-- 回答エリア(質問後のみ表示) -->
        <section v-if="askedQuestion" class="answer-block">
            <p class="asked">Q. {{ askedQuestion }}</p>

            <!-- 引用元カード(sources は回答より先に届く) -->
            <div v-if="sources.length" class="sources">
                <div class="sources-head">引用元</div>
                <div class="source-cards">
                    <div v-for="s in sources" :key="s.chunk_id" class="source-card">
                        <div class="source-title">
                            {{ s.document_title ?? '(不明な文書)' }}
                        </div>
                        <div class="source-section muted">{{ s.section ?? '—' }}</div>
                        <div class="source-meta">
                            <span class="badge">類似度 {{ fmtScore(s.score) }}</span>
                            <span class="muted">#{{ s.rank }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 根拠なし(no_answer)専用表示 -->
            <div v-if="noAnswer" class="card no-answer">
                <strong>回答できる根拠が見つかりませんでした。</strong>
                <p class="muted" style="margin-bottom: 0">
                    関連する社内文書が見つからなかったため、推測での回答は行いません。質問の表現を変えるか、管理者に文書の追加を依頼してください。
                </p>
            </div>

            <!-- 回答本文(トークンを逐次追記) -->
            <div v-else-if="answer || streaming" class="card answer">
                <div class="answer-text">{{ answer }}<span v-if="streaming" class="caret">▋</span></div>
            </div>

            <!-- 完了メタ情報 + フィードバック -->
            <div v-if="result" class="result-footer">
                <div class="meta muted">
                    <span v-if="result.cached" class="badge">キャッシュ応答</span>
                    <span>応答時間 {{ result.latency_ms }} ms</span>
                </div>
                <div v-if="!noAnswer" class="feedback">
                    <button
                        class="ghost small"
                        :disabled="feedbackSent !== null"
                        :class="{ active: feedbackSent === 'good' }"
                        @click="onFeedback('good')"
                    >
                        👍 役に立った
                    </button>
                    <button
                        class="ghost small"
                        :disabled="feedbackSent !== null"
                        :class="{ active: feedbackSent === 'bad' }"
                        @click="onFeedback('bad')"
                    >
                        👎 改善が必要
                    </button>
                    <span v-if="feedbackSent" class="muted">フィードバックありがとうございます。</span>
                </div>
            </div>
        </section>

        <div v-else class="empty muted">
            社内文書(施術マニュアル・予約/キャンセルポリシー・術後ケアFAQ 等)から根拠付きで回答します。
        </div>
    </div>
</template>

<style scoped>
.ask-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.75rem;
}

.answer-block {
    margin-top: 1.5rem;
}

.asked {
    font-weight: 600;
    margin: 0 0 0.9rem;
}

.sources-head {
    font-size: 0.8rem;
    color: var(--c-muted);
    margin-bottom: 0.5rem;
}

.source-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.6rem;
    margin-bottom: 1rem;
}

.source-card {
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-left: 3px solid var(--c-primary);
    border-radius: 8px;
    padding: 0.6rem 0.75rem;
}

.source-title {
    font-weight: 600;
    font-size: 0.9rem;
}

.source-section {
    font-size: 0.8rem;
    margin: 0.15rem 0 0.5rem;
}

.source-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.answer .answer-text {
    white-space: pre-wrap;
    word-break: break-word;
}

.caret {
    color: var(--c-primary);
    animation: blink 1s steps(2, start) infinite;
}

@keyframes blink {
    to {
        visibility: hidden;
    }
}

.no-answer {
    border-left: 3px solid var(--c-danger);
}

.result-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

.meta {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.82rem;
}

.feedback {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.feedback .active {
    background: var(--c-accent);
    border-color: var(--c-primary);
}

.empty {
    margin-top: 2.5rem;
    text-align: center;
}
</style>
