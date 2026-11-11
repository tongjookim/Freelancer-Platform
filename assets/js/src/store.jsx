/**
 * 인증 상태 컨텍스트
 * - 앱 시작 시 /auth/me 로 세션 복원 + CSRF 토큰 주입
 */
import React, { createContext, useContext, useEffect, useState } from 'react';
import { AuthApi, setCsrfToken } from './api.js';
import { navigate } from './ui.jsx';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    AuthApi.me()
      .then((d) => { setCsrfToken(d.csrf_token); setUser(d.user); })
      .catch(() => {})
      .finally(() => setReady(true));
  }, []);

  const login = (user, csrf) => { if (csrf) setCsrfToken(csrf); setUser(user); };
  const logout = async () => {
    try { await AuthApi.logout(); } catch (_) {}
    setUser(null);
    navigate('/');
  };

  return (
    <AuthContext.Provider value={{ user, ready, login, logout, setUser }}>
      {children}
    </AuthContext.Provider>
  );
}

export const useAuth = () => useContext(AuthContext);
