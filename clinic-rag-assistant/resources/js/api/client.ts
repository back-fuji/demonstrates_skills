// APIクライアント。Sanctum SPA(Cookie)認証を前提とする。
// axios は withCredentials / withXSRFToken を有効化しているため、
// 通常リクエストでは XSRF-TOKEN cookie を自動で X-XSRF-TOKEN ヘッダへ載せる。
// ただし SSE の検索だけは fetch を使うため、cookie を都度自前で読む。
import axios from 'axios';

// ---- 型定義 -------------------------------------------------------------

// ログインユーザー(GET /api/user, POST /api/login のレスポンス)
export interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    is_admin: boolean;
}

// 引用元チャンク(SSE sources / 履歴 messages 共通)
export interface SourceChunk {
    chunk_id: number;
    document_title: string | null;
    section: string | null;
    score: number;
    rank: number;
}

// 検索完了イベントのペイロード(done / no_answer 共通)
export interface SearchDone {
    answer_id: string;
    cached: boolean;
    latency_ms: number;
}

// SSE 受信時のコールバック群
export interface SearchHandlers {
    onSession?: (sessionId: string) => void;
    onSources?: (chunks: SourceChunk[]) => void;
    onToken?: (text: string) => void;
    onDone?: (payload: SearchDone) => void;
    onNoAnswer?: (payload: SearchDone) => void;
    onError?: (message: string) => void;
}

// 文書一覧の1行(GET /api/documents の paginate data)
export interface DocumentListItem {
    id: number;
    title: string;
    category: string;
    status: string;
    version: number;
    updated_at: string;
}

// 文書詳細(GET /api/documents/{id})
export interface DocumentDetail {
    id: number;
    title: string;
    category: string;
    content: string;
    status: string;
    version: number;
    error_message: string | null;
    chunk_count: number;
    updated_at: string;
}

// Laravel paginate のレスポンス形
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

// ---- axios インスタンス --------------------------------------------------

const http = axios.create({
    baseURL: '/',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    },
});

// cookie から指定名の値を取り出す(URLデコード込み)。
function readCookie(name: string): string | null {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith(`${name}=`));
    return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null;
}

// CSRF Cookie を取得する。login/logout の前に必ず呼ぶ。
export async function fetchCsrfCookie(): Promise<void> {
    await http.get('/sanctum/csrf-cookie');
}

// ---- 認証 ---------------------------------------------------------------

export async function login(email: string, password: string): Promise<User> {
    await fetchCsrfCookie();
    const res = await http.post<User>('/api/login', { email, password });
    return res.data;
}

export async function logout(): Promise<void> {
    await http.post('/api/logout');
}

// 現在のユーザー。未認証時は 401 を投げる。
export async function currentUser(): Promise<User> {
    const res = await http.get<User>('/api/user');
    return res.data;
}

// ---- 履歴・フィードバック -----------------------------------------------

export async function fetchMessages(sessionId: string): Promise<unknown> {
    const res = await http.get(`/api/sessions/${sessionId}/messages`);
    return res.data;
}

export async function sendFeedback(
    answerId: string,
    rating: 'good' | 'bad',
    comment?: string,
): Promise<void> {
    await http.post(`/api/answers/${answerId}/feedback`, { rating, comment });
}

// ---- 文書管理(管理者専用) ---------------------------------------------

export async function listDocuments(page = 1): Promise<Paginated<DocumentListItem>> {
    const res = await http.get<Paginated<DocumentListItem>>('/api/documents', {
        params: { page },
    });
    return res.data;
}

export async function getDocument(id: number): Promise<DocumentDetail> {
    const res = await http.get<DocumentDetail>(`/api/documents/${id}`);
    return res.data;
}

export async function createDocument(payload: {
    title: string;
    category: string;
    content: string;
}): Promise<{ document_id: number; status: string }> {
    const res = await http.post('/api/documents', payload);
    return res.data;
}

export async function updateDocument(
    id: number,
    version: number,
    payload: { content: string; title?: string; category?: string },
): Promise<{ document_id: number; status: string; version: number }> {
    // 楽観ロック: If-Match に現在の version を指定する(欠落は 428, 不一致は 409)。
    const res = await http.put(`/api/documents/${id}`, payload, {
        headers: { 'If-Match': String(version) },
    });
    return res.data;
}

export async function deleteDocument(id: number): Promise<void> {
    await http.delete(`/api/documents/${id}`);
}

// ---- 検索(SSE ストリーミング) -----------------------------------------

// POST /api/search を fetch で叩き、SSE フレームを自前パースしてコールバックへ流す。
// EventSource は POST 不可のため fetch + ReadableStream を使う。
export async function searchStream(
    body: { question: string; session_id?: string },
    handlers: SearchHandlers,
    signal?: AbortSignal,
): Promise<void> {
    // XSRF-TOKEN は login/logout 後に再生成されるため、リクエスト直前に cookie から読む。
    const token = readCookie('XSRF-TOKEN') ?? '';

    let res: Response;
    try {
        res = await fetch('/api/search', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'X-XSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
            },
            body: JSON.stringify(body),
            signal,
        });
    } catch (e) {
        handlers.onError?.('ネットワークエラーが発生しました。');
        return;
    }

    if (!res.ok || res.body === null) {
        handlers.onError?.(`検索に失敗しました(HTTP ${res.status})。`);
        return;
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    // eslint-disable-next-line no-constant-condition
    while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });

        // フレーム区切りは空行(\n\n)。完結したフレームだけ処理する。
        let sep: number;
        while ((sep = buffer.indexOf('\n\n')) !== -1) {
            const frame = buffer.slice(0, sep);
            buffer = buffer.slice(sep + 2);
            dispatchFrame(frame, handlers);
        }
    }
}

// 1フレーム("event: X\ndata: {json}")を解析してコールバックを呼ぶ。
function dispatchFrame(frame: string, handlers: SearchHandlers): void {
    let event = 'message';
    const dataLines: string[] = [];
    for (const line of frame.split('\n')) {
        if (line.startsWith('event:')) {
            event = line.slice(6).trim();
        } else if (line.startsWith('data:')) {
            dataLines.push(line.slice(5).trim());
        }
    }
    if (dataLines.length === 0) return;

    let data: any;
    try {
        data = JSON.parse(dataLines.join('\n'));
    } catch {
        return;
    }

    switch (event) {
        case 'session':
            handlers.onSession?.(data.session_id);
            break;
        case 'sources':
            handlers.onSources?.(data.chunks ?? []);
            break;
        case 'token':
            handlers.onToken?.(data.text ?? '');
            break;
        case 'done':
            handlers.onDone?.(data);
            break;
        case 'no_answer':
            handlers.onNoAnswer?.(data);
            break;
    }
}
