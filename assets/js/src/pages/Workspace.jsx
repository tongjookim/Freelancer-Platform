import React, { useEffect, useState } from 'react';
import { MarketApi, PortfolioApi, uploadFile, ApiError } from '../api.js';
import { useAuth } from '../store.jsx';
import { Card, Button, Field, ErrorBox, Spinner, won, navigate } from '../ui.jsx';

/** 프리랜서/의뢰인 마이 워크스페이스 */
export default function WorkspacePage() {
  const { user } = useAuth();
  if (!user) return null;
  return user.role === 'freelancer' ? <FreelancerWorkspace /> : <ClientWorkspace />;
}

function FreelancerWorkspace() {
  const [items, setItems] = useState(null);
  const [cats, setCats] = useState([]);
  const [form, setForm] = useState({ title: '', category_id: '', price: '', delivery_days: 3, description: '' });
  const [error, setError] = useState('');
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  const load = () => MarketApi.services('?mine=1').then((d) => setItems(d.services)).catch((e) => setError(e.message));
  useEffect(() => { load(); MarketApi.categories().then((d) => setCats(d.categories)).catch(() => {}); }, []);

  const submit = async (e) => {
    e.preventDefault(); setError('');
    try {
      await MarketApi.createService(form);
      setForm({ title: '', category_id: '', price: '', delivery_days: 3, description: '' });
      load();
    } catch (err) { setError(err instanceof ApiError ? err.message : '등록 실패'); }
  };

  return (
    <div className="space-y-5">
    <div className="grid gap-5 lg:grid-cols-2">
      <form onSubmit={submit} className="card space-y-3">
        <h2 className="font-semibold">서비스 등록</h2>
        <ErrorBox message={error} />
        <Field label="제목"><input className="input" value={form.title} onChange={set('title')} required /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="카테고리">
            <select className="input" value={form.category_id} onChange={set('category_id')}>
              <option value="">선택</option>
              {cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <Field label="가격(원)"><input className="input" type="number" value={form.price} onChange={set('price')} required /></Field>
        </div>
        <Field label="작업 기간(일)"><input className="input" type="number" value={form.delivery_days} onChange={set('delivery_days')} /></Field>
        <Field label="설명"><textarea className="input" rows="4" value={form.description} onChange={set('description')} /></Field>
        <Button type="submit">등록</Button>
      </form>

      <Card>
        <h2 className="mb-2 font-semibold">내 서비스</h2>
        {!items ? <Spinner label="불러오는 중…" /> : items.length === 0 ? <p className="text-slate-400">등록한 서비스가 없습니다.</p> : items.map((s) => (
          <div key={s.id} className="flex items-center justify-between border-b py-2 last:border-0">
            <div>
              <p className="font-medium">{s.title}</p>
              <p className="text-sm text-slate-500">{won(s.price)} · {s.delivery_days}일 · {s.status}</p>
            </div>
            <Button variant="muted" onClick={() => navigate(`/services/${s.id}`)}>보기</Button>
          </div>
        ))}
      </Card>
      </div>
      <PortfolioPanel />
    </div>
  );
}

/* ================= 포트폴리오 관리 ================= */
function PortfolioPanel() {
  const { user } = useAuth();
  const [items, setItems] = useState(null);
  const [form, setForm] = useState({ title: '', description: '', link_url: '' });
  const [thumb, setThumb] = useState(null);
  const [error, setError] = useState('');

  const load = () => PortfolioApi.list(user.id).then((d) => setItems(d.portfolios)).catch((e) => setError(e.message));
  useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

  const submit = async (e) => {
    e.preventDefault(); setError('');
    try {
      let thumbnail;
      if (thumb) thumbnail = (await uploadFile(thumb, 'portfolio')).path;
      await PortfolioApi.create({ ...form, thumbnail });
      setForm({ title: '', description: '', link_url: '' }); setThumb(null);
      load();
    } catch (err) { setError(err instanceof ApiError ? err.message : '등록 실패'); }
  };

  const remove = async (id) => {
    if (!confirm('포트폴리오를 삭제할까요?')) return;
    try { await PortfolioApi.remove(id); load(); } catch (err) { setError(err.message); }
  };

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <form onSubmit={submit} className="card space-y-3">
        <h2 className="font-semibold">포트폴리오 등록</h2>
        <ErrorBox message={error} />
        <Field label="제목"><input className="input" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required /></Field>
        <Field label="설명"><textarea className="input" rows="3" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
        <Field label="외부 링크"><input className="input" value={form.link_url} onChange={(e) => setForm({ ...form, link_url: e.target.value })} /></Field>
        <Field label="썸네일 이미지"><input type="file" accept="image/*" className="text-sm" onChange={(e) => setThumb(e.target.files[0])} /></Field>
        <Button type="submit">등록</Button>
      </form>

      <Card>
        <h2 className="mb-2 font-semibold">내 포트폴리오</h2>
        {!items ? <Spinner label="불러오는 중…" /> : items.length === 0 ? <p className="text-slate-400">등록한 포트폴리오가 없습니다.</p> : (
          <div className="grid grid-cols-2 gap-3">
            {items.map((p) => (
              <div key={p.id} className="rounded-lg border p-2">
                {p.thumbnail && <img src={`/${p.thumbnail}`} alt="" className="mb-2 h-28 w-full rounded object-cover" />}
                <p className="text-sm font-medium">{p.title}</p>
                <button className="mt-1 text-xs text-rose-600" onClick={() => remove(p.id)}>삭제</button>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}

function ClientWorkspace() {
  const [items, setItems] = useState(null);
  const [cats, setCats] = useState([]);
  const [form, setForm] = useState({ title: '', category_id: '', budget: '', deadline: '', description: '' });
  const [attach, setAttach] = useState(null);
  const [error, setError] = useState('');
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  const load = () => MarketApi.projects('?mine=1').then((d) => setItems(d.projects)).catch((e) => setError(e.message));
  useEffect(() => { load(); MarketApi.categories().then((d) => setCats(d.categories)).catch(() => {}); }, []);

  const submit = async (e) => {
    e.preventDefault(); setError('');
    try {
      let attachment;
      if (attach) attachment = (await uploadFile(attach, 'project')).path;
      await MarketApi.createProject({ ...form, attachment });
      setForm({ title: '', category_id: '', budget: '', deadline: '', description: '' });
      setAttach(null);
      load();
    } catch (err) { setError(err instanceof ApiError ? err.message : '등록 실패'); }
  };

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <form onSubmit={submit} className="card space-y-3">
        <h2 className="font-semibold">작업 요청서 작성</h2>
        <ErrorBox message={error} />
        <Field label="제목"><input className="input" value={form.title} onChange={set('title')} required /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="카테고리">
            <select className="input" value={form.category_id} onChange={set('category_id')}>
              <option value="">선택</option>
              {cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <Field label="예산(원)"><input className="input" type="number" value={form.budget} onChange={set('budget')} /></Field>
        </div>
        <Field label="마감일"><input className="input" type="date" value={form.deadline} onChange={set('deadline')} /></Field>
        <Field label="상세 요청"><textarea className="input" rows="4" value={form.description} onChange={set('description')} /></Field>
        <Field label="참고 파일 첨부"><input type="file" className="text-sm" onChange={(e) => setAttach(e.target.files[0])} /></Field>
        <Button type="submit">요청서 등록</Button>
      </form>

      <Card>
        <h2 className="mb-2 font-semibold">내 프로젝트</h2>
        {!items ? <Spinner label="불러오는 중…" /> : items.length === 0 ? <p className="text-slate-400">등록한 프로젝트가 없습니다.</p> : items.map((p) => (
          <div key={p.id} className="flex items-center justify-between border-b py-2 last:border-0">
            <div>
              <p className="font-medium">{p.title}</p>
              <p className="text-sm text-slate-500">{p.budget ? won(p.budget) : '협의'} · 지원 {p.proposal_count}명 · {p.status}</p>
            </div>
            <Button variant="muted" onClick={() => navigate(`/projects/${p.id}`)}>지원 보기</Button>
          </div>
        ))}
      </Card>
    </div>
  );
}
