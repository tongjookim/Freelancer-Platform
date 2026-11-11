/**
 * React 앱 엔트리 (index.php 의 <div id="root"> 에 마운트)
 * - 인증 컨텍스트(AuthProvider) + 해시 라우팅(App)
 */
import React from 'react';
import { createRoot } from 'react-dom/client';
import { AuthProvider } from './store.jsx';
import App from './App.jsx';
import '../../css/src/input.css'; // Tailwind 소스 → 빌드 시 assets/css/default.css 로 출력

const el = document.getElementById('root');
if (el) {
  createRoot(el).render(
    <AuthProvider>
      <App />
    </AuthProvider>
  );
}
