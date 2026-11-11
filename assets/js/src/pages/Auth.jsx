import React, { useState } from 'react';
import { AuthApi, ApiError } from '../api.js';
import { useAuth } from '../store.jsx';
import { Card, Button, Field, ErrorBox, navigate } from '../ui.jsx';

export default function AuthPage() {
  const { login } = useAuth();
  const [mode, setMode] = useState('login'); // login | register

  return (
    <div className="mx-auto mt-12 max-w-md">
      <div className="text-center mb-6">
        <div className="mx-auto mb-3 h-12 w-12 rounded-2xl bg-gradient-to-br from-brand-600 to-indigo-600" />
        <h1 className="text-2xl font-extrabold">프리랜서 마켓</h1>
        <p className="text-sm text-slate-500">안전한 거래를 시작하세요</p>
      </div>
      <div className="card space-y-5 p-8">
        <div className="grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1">
          <button className={`rounded-lg py-2 font-semibold transition ${mode === 'login' ? 'bg-white shadow' : 'text-slate-600'}`} onClick={() => setMode('login')}>로그인</button>
          <button className={`rounded-lg py-2 font-semibold transition ${mode === 'register' ? 'bg-white shadow' : 'text-slate-600'}`} onClick={() => setMode('register')}>회원가입</button>
        </div>
        {mode === 'login' ? <LoginForm onDone={login} /> : <RegisterForm onDone={login} />}
      </div>
    </div>
  );
}

function LoginForm({ onDone }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');

  const submit = async (e) => {
    e.preventDefault(); setError('');
    try {
      const d = await AuthApi.login(email, password);
      onDone(d.user, d.csrf_token);
      navigate('/');
    } catch (err) { setError(err instanceof ApiError ? err.message : '로그인 실패'); }
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <ErrorBox message={error} />
      <Field label="이메일"><input className="input" value={email} onChange={(e) => setEmail(e.target.value)} required /></Field>
      <Field label="비밀번호"><input className="input" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required /></Field>
      <Button type="submit" className="w-full">로그인</Button>
    </form>
  );
}

function RegisterForm({ onDone }) {
  const [form, setForm] = useState({ role: 'client', name: '', email: '', password: '', phone: '' });
  const [error, setError] = useState('');
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  const submit = async (e) => {
    e.preventDefault(); setError('');
    try {
      const d = await AuthApi.register(form);
      onDone(d.user, d.csrf_token);
      navigate('/');
    } catch (err) { setError(err instanceof ApiError ? err.message : '가입 실패'); }
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      <ErrorBox message={error} />
      <Field label="가입 유형">
        <select className="input" value={form.role} onChange={set('role')}>
          <option value="client">의뢰인 (Client)</option>
          <option value="freelancer">프리랜서 (Freelancer)</option>
        </select>
      </Field>
      <Field label="이름"><input className="input" value={form.name} onChange={set('name')} required /></Field>
      <Field label="이메일"><input className="input" type="email" value={form.email} onChange={set('email')} required /></Field>
      <Field label="연락처 (선택)"><input className="input" value={form.phone} onChange={set('phone')} /></Field>
      <Field label="비밀번호" hint="8자 이상"><input className="input" type="password" value={form.password} onChange={set('password')} required /></Field>
      <Button type="submit" className="w-full">가입하기</Button>
    </form>
  );
}
