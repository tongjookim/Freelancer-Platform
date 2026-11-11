import React, { useState } from 'react';
import { AuthApi, ApiError } from '../api.js';
import { useAuth } from '../store.jsx';
import { Card, Button, Field, ErrorBox } from '../ui.jsx';

const ROLE_LABEL = { client: '의뢰인', freelancer: '프리랜서', admin: '관리자' };

export default function ProfilePage() {
  const { user } = useAuth();
  const [form, setForm] = useState({ current_password: '', new_password: '', confirm: '' });
  const [error, setError] = useState('');
  const [done, setDone] = useState('');
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  const submit = async (e) => {
    e.preventDefault(); setError(''); setDone('');
    if (form.new_password !== form.confirm) { setError('새 비밀번호가 일치하지 않습니다.'); return; }
    try {
      await AuthApi.changePassword(form.current_password, form.new_password);
      setDone('비밀번호가 변경되었습니다.');
      setForm({ current_password: '', new_password: '', confirm: '' });
    } catch (err) { setError(err instanceof ApiError ? err.message : '변경 실패'); }
  };

  if (!user) return null;

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card className="space-y-2">
        <h2 className="font-semibold">내 정보</h2>
        <p className="text-sm text-slate-600">이름: <span className="font-medium">{user.name}</span></p>
        <p className="text-sm text-slate-600">이메일: <span className="font-medium">{user.email}</span></p>
        <p className="text-sm text-slate-600">유형: <span className="font-medium">{ROLE_LABEL[user.role] || user.role}</span></p>
        <p className="text-sm text-slate-600">상태: <span className="font-medium">{user.status}</span></p>
      </Card>

      <form onSubmit={submit} className="card space-y-3">
        <h2 className="font-semibold">비밀번호 변경</h2>
        <ErrorBox message={error} />
        {done && <p className="badge block bg-emerald-50 text-emerald-700">{done}</p>}
        <Field label="현재 비밀번호"><input className="input" type="password" value={form.current_password} onChange={set('current_password')} required /></Field>
        <Field label="새 비밀번호" hint="8자 이상"><input className="input" type="password" value={form.new_password} onChange={set('new_password')} required /></Field>
        <Field label="새 비밀번호 확인"><input className="input" type="password" value={form.confirm} onChange={set('confirm')} required /></Field>
        <Button type="submit">변경</Button>
      </form>
    </div>
  );
}
