// SPA エントリポイント。Vue アプリを作成し、ルーターとグローバルCSSを適用する。
// axios の Sanctum SPA 設定(baseURL/withCredentials/withXSRFToken)は
// api/client.ts の axios インスタンス側で行う。
import { createApp } from 'vue';
import router from './router';
import App from './App.vue';
import './styles.css';

createApp(App).use(router).mount('#app');
