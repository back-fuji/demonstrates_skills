<script setup lang="ts">
// ルートコンポーネント。ヘッダー(ブランド/ナビ)と <router-view> を配置する。
import { useRouter } from 'vue-router';
import { authState, setUser } from './stores/auth';
import { logout as apiLogout } from './api/client';

const router = useRouter();

async function onLogout() {
    try {
        await apiLogout();
    } finally {
        setUser(null);
        router.push({ name: 'login' });
    }
}
</script>

<template>
    <header class="app-header">
        <div class="brand">
            Lumière Clinic <small>ナレッジ検索アシスタント</small>
        </div>
        <nav class="app-nav" v-if="authState.user">
            <router-link :to="{ name: 'chat' }">チャット</router-link>
            <router-link v-if="authState.user.is_admin" :to="{ name: 'admin' }">
                文書管理
            </router-link>
            <span class="who">{{ authState.user.name }}({{ authState.user.role }})</span>
            <button class="ghost small" @click="onLogout">ログアウト</button>
        </nav>
    </header>
    <router-view />
</template>
