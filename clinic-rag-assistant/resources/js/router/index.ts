// SPA ルーター。/login・/(チャット)・/admin(管理者専用)。
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { authState, ensureLoaded } from '../stores/auth';

const routes: RouteRecordRaw[] = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../pages/Login.vue'),
        meta: { guest: true },
    },
    {
        path: '/',
        name: 'chat',
        component: () => import('../pages/Chat.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/admin',
        name: 'admin',
        component: () => import('../pages/Admin.vue'),
        meta: { requiresAuth: true, requiresAdmin: true },
    },
    // 未定義パスはチャットへ
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    // 初回のみ認証状態を確定させる。
    await ensureLoaded();
    const user = authState.user;

    // 認証必須ルートに未認証でアクセス → ログインへ
    if (to.meta.requiresAuth && user === null) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }
    // 管理者専用ルートに非管理者(staff)でアクセス → チャットへ
    if (to.meta.requiresAdmin && !user?.is_admin) {
        return { name: 'chat' };
    }
    // ログイン済みで /login に来たらチャットへ
    if (to.meta.guest && user !== null) {
        return { name: 'chat' };
    }
    return true;
});

export default router;
