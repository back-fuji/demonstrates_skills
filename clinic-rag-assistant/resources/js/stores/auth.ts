// 認証状態の最小ストア(外部ライブラリなしの reactive)。
// ルーターガードと各ページで共有する。
import { reactive } from 'vue';
import { currentUser, type User } from '../api/client';

interface AuthState {
    user: User | null;
    ready: boolean; // 初回の currentUser() 判定が済んだか
}

export const authState = reactive<AuthState>({
    user: null,
    ready: false,
});

// 起動時に一度だけ現在ユーザーを確認する。未認証(401)なら user=null。
export async function ensureLoaded(): Promise<void> {
    if (authState.ready) return;
    try {
        authState.user = await currentUser();
    } catch {
        authState.user = null;
    } finally {
        authState.ready = true;
    }
}

export function setUser(user: User | null): void {
    authState.user = user;
    authState.ready = true;
}
