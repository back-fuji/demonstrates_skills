/// <reference types="vite/client" />

// .vue 単一ファイルコンポーネントを TypeScript から import できるようにする型定義。
declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent<{}, {}, any>;
    export default component;
}
