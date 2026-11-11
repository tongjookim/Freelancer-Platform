// React(JSX) + Tailwind 소스를 PHP 구조에 맞는 정적 자산으로 번들링한다.
//   npx vite build
//   → JS:  /assets/js/script.js   (index.php 가 로드)
//   → CSS: /assets/css/default.css
//
// Tailwind 를 쓰는 경우 tailwind.config.js 의 content 에
// index.php, includes/**, assets/js/src/** 를 지정한다.
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'assets',
    emptyOutDir: false,
    rollupOptions: {
      // JS 엔트리 (main.jsx 안에서 input.css 를 import 한다)
      input: resolve(import.meta.dirname, 'assets/js/src/main.jsx'),
      output: {
        entryFileNames: 'js/script.js',
        chunkFileNames: 'js/[name].js',
        // CSS 자산은 항상 고정 경로로 출력 (head.php 가 로드)
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'css/default.css';
          }
          return 'assets/[name][extname]';
        },
      },
    },
  },
});
