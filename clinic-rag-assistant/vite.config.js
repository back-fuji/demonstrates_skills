import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // 既存の CSS/JS に加えて Vue SPA のエントリ(app.ts)を追加。
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Vite/Laravel でのアセット解決(SFC 内 <img> 等)
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
