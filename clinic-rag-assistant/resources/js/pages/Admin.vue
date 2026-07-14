<script setup lang="ts">
// 文書管理画面(管理者専用)。一覧・新規アップロード・詳細/更新/削除。
// ルーターガードで管理者以外は到達しないが、二重防御で is_admin も確認する。
import { onMounted, ref } from 'vue';
import {
    listDocuments,
    getDocument,
    createDocument,
    updateDocument,
    deleteDocument,
    type DocumentListItem,
    type DocumentDetail,
    type Paginated,
} from '../api/client';
import { authState } from '../stores/auth';

const page = ref<Paginated<DocumentListItem> | null>(null);
const loading = ref(false);
const listError = ref('');

// 新規アップロードフォーム
const form = ref({ title: '', category: 'policy', content: '' });
const creating = ref(false);
const createMsg = ref('');

// 詳細/編集
const detail = ref<DocumentDetail | null>(null);
const detailContent = ref('');
const detailMsg = ref('');
const saving = ref(false);

async function loadList(p = 1) {
    loading.value = true;
    listError.value = '';
    try {
        page.value = await listDocuments(p);
    } catch (e: any) {
        listError.value = e?.response?.data?.detail || '一覧の取得に失敗しました。';
    } finally {
        loading.value = false;
    }
}

async function onCreate() {
    if (form.value.title.trim() === '' || form.value.content.trim() === '') return;
    creating.value = true;
    createMsg.value = '';
    try {
        const res = await createDocument({
            title: form.value.title.trim(),
            category: form.value.category.trim(),
            content: form.value.content,
        });
        createMsg.value = `受付ました(ID: ${res.document_id}、取り込み中)。`;
        form.value = { title: '', category: 'policy', content: '' };
        await loadList(page.value?.current_page ?? 1);
    } catch (e: any) {
        createMsg.value = e?.response?.data?.detail || 'アップロードに失敗しました。';
    } finally {
        creating.value = false;
    }
}

async function openDetail(id: number) {
    detailMsg.value = '';
    try {
        detail.value = await getDocument(id);
        detailContent.value = detail.value.content;
    } catch (e: any) {
        detailMsg.value = e?.response?.data?.detail || '詳細の取得に失敗しました。';
    }
}

async function onSave() {
    if (!detail.value) return;
    saving.value = true;
    detailMsg.value = '';
    try {
        const res = await updateDocument(detail.value.id, detail.value.version, {
            content: detailContent.value,
        });
        detailMsg.value = `更新を受付ました(version ${res.version}、再取り込み中)。`;
        await loadList(page.value?.current_page ?? 1);
        await openDetail(detail.value.id);
    } catch (e: any) {
        const status = e?.response?.status;
        if (status === 409) {
            detailMsg.value = '競合しました。他の管理者が先に更新しています。最新版を再読み込みしてください。';
        } else if (status === 428) {
            detailMsg.value = 'version 指定が必要です(If-Match)。';
        } else {
            detailMsg.value = e?.response?.data?.detail || '更新に失敗しました。';
        }
    } finally {
        saving.value = false;
    }
}

async function onDelete() {
    if (!detail.value) return;
    if (!confirm(`「${detail.value.title}」を削除しますか?`)) return;
    try {
        await deleteDocument(detail.value.id);
        detail.value = null;
        await loadList(page.value?.current_page ?? 1);
    } catch (e: any) {
        detailMsg.value = e?.response?.data?.detail || '削除に失敗しました。';
    }
}

onMounted(() => loadList());
</script>

<template>
    <div class="container" v-if="authState.user?.is_admin">
        <h1 style="font-size: 1.3rem">文書管理</h1>

        <!-- 新規アップロード -->
        <div class="card" style="margin-bottom: 1.5rem">
            <h2 style="font-size: 1.05rem; margin-top: 0">新規アップロード</h2>
            <form @submit.prevent="onCreate">
                <div class="field">
                    <label for="t">タイトル</label>
                    <input id="t" v-model="form.title" required />
                </div>
                <div class="field">
                    <label for="c">カテゴリ</label>
                    <select id="c" v-model="form.category">
                        <option value="policy">policy(ポリシー)</option>
                        <option value="manual">manual(マニュアル)</option>
                        <option value="faq">faq(FAQ)</option>
                        <option value="other">other(その他)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="body">本文(Markdown)</label>
                    <textarea id="body" v-model="form.content" rows="6" required></textarea>
                </div>
                <button type="submit" :disabled="creating">
                    {{ creating ? '送信中…' : 'アップロード' }}
                </button>
                <p v-if="createMsg" class="muted" style="margin-bottom: 0">{{ createMsg }}</p>
            </form>
        </div>

        <!-- 一覧 -->
        <div class="card">
            <div style="display: flex; align-items: center; justify-content: space-between">
                <h2 style="font-size: 1.05rem; margin: 0">文書一覧</h2>
                <button class="ghost small" @click="loadList(page?.current_page ?? 1)">再読み込み</button>
            </div>
            <p v-if="listError" class="error">{{ listError }}</p>
            <p v-if="loading" class="muted">読み込み中…</p>

            <table v-if="page && page.data.length" class="docs">
                <thead>
                    <tr>
                        <th>タイトル</th>
                        <th>カテゴリ</th>
                        <th>状態</th>
                        <th>版</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in page.data" :key="d.id" @click="openDetail(d.id)">
                        <td>{{ d.title }}</td>
                        <td><span class="badge">{{ d.category }}</span></td>
                        <td>{{ d.status }}</td>
                        <td>v{{ d.version }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else-if="page && !loading" class="muted">文書がまだありません。</p>

            <div v-if="page && page.last_page > 1" class="pager">
                <button
                    class="ghost small"
                    :disabled="page.current_page <= 1"
                    @click="loadList(page.current_page - 1)"
                >
                    前へ
                </button>
                <span class="muted">{{ page.current_page }} / {{ page.last_page }}</span>
                <button
                    class="ghost small"
                    :disabled="page.current_page >= page.last_page"
                    @click="loadList(page.current_page + 1)"
                >
                    次へ
                </button>
            </div>
        </div>

        <!-- 詳細 -->
        <div v-if="detail" class="card" style="margin-top: 1.5rem">
            <div style="display: flex; align-items: center; justify-content: space-between">
                <h2 style="font-size: 1.05rem; margin: 0">{{ detail.title }}</h2>
                <button class="ghost small" @click="detail = null">閉じる</button>
            </div>
            <p class="muted" style="margin: 0.4rem 0">
                <span class="badge">{{ detail.category }}</span>
                状態: {{ detail.status }} / 版: v{{ detail.version }} / チャンク数: {{ detail.chunk_count }}
            </p>
            <p v-if="detail.error_message" class="error">取り込みエラー: {{ detail.error_message }}</p>

            <div class="field">
                <label for="dc">本文(編集して保存すると再取り込みされます)</label>
                <textarea id="dc" v-model="detailContent" rows="8"></textarea>
            </div>
            <div style="display: flex; gap: 0.6rem">
                <button :disabled="saving" @click="onSave">
                    {{ saving ? '保存中…' : '保存(再取り込み)' }}
                </button>
                <button class="ghost" @click="onDelete">削除</button>
            </div>
            <p v-if="detailMsg" class="muted" style="margin-bottom: 0">{{ detailMsg }}</p>
        </div>
    </div>
</template>

<style scoped>
.docs {
    width: 100%;
    border-collapse: collapse;
    margin-top: 0.75rem;
    font-size: 0.9rem;
}

.docs th,
.docs td {
    text-align: left;
    padding: 0.55rem 0.5rem;
    border-bottom: 1px solid var(--c-border);
}

.docs th {
    color: var(--c-muted);
    font-weight: 600;
    font-size: 0.8rem;
}

.docs tbody tr {
    cursor: pointer;
}

.docs tbody tr:hover {
    background: var(--c-accent);
}

.pager {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-top: 1rem;
}
</style>
