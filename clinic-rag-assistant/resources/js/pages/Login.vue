<script setup lang="ts">
// ログイン画面。デモ用アカウントのヒントを表示する。
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { login } from '../api/client';
import { setUser } from '../stores/auth';

const email = ref('admin@example.com');
const password = ref('password');
const error = ref('');
const loading = ref(false);

const route = useRoute();
const router = useRouter();

async function onSubmit() {
    error.value = '';
    loading.value = true;
    try {
        const user = await login(email.value, password.value);
        setUser(user);
        const redirect = (route.query.redirect as string) || '/';
        router.push(redirect);
    } catch (e: any) {
        // バリデーション/認証失敗のメッセージを拾う
        error.value =
            e?.response?.data?.errors?.email?.[0] ||
            e?.response?.data?.detail ||
            'ログインに失敗しました。';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="container" style="max-width: 420px; margin-top: 3rem">
        <div class="card">
            <h1 style="margin-top: 0; font-size: 1.3rem">ログイン</h1>
            <p class="muted">社内スタッフ向けナレッジ検索システムです。</p>

            <form @submit.prevent="onSubmit">
                <div class="field">
                    <label for="email">メールアドレス</label>
                    <input id="email" v-model="email" type="email" autocomplete="username" required />
                </div>
                <div class="field">
                    <label for="password">パスワード</label>
                    <input
                        id="password"
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        required
                    />
                </div>
                <button type="submit" :disabled="loading" style="width: 100%">
                    {{ loading ? '認証中…' : 'ログイン' }}
                </button>
                <p v-if="error" class="error">{{ error }}</p>
            </form>

            <div class="card" style="margin-top: 1.25rem; background: var(--c-accent); box-shadow: none">
                <strong style="font-size: 0.85rem">デモ用アカウント</strong>
                <ul class="muted" style="margin: 0.4rem 0 0; padding-left: 1.1rem">
                    <li>管理者: admin@example.com</li>
                    <li>スタッフ: staff@example.com</li>
                    <li>パスワード: password</li>
                </ul>
            </div>
        </div>
    </div>
</template>
