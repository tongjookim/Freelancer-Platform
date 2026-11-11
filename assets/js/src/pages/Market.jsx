import React, { useEffect, useState } from 'react';
import { MarketApi, OrderApi, PortfolioApi, ReviewApi, ApiError } from '../api.js';
import { useAuth } from '../store.jsx';
import { Card, Button, Field, ErrorBox, Spinner, won, navigate } from '../ui.jsx';

/* ================= 마켓 홈 (서비스 / 프로젝트 탭) ================= */
export default function MarketPage() {
  const [tab, setTab] = useState('services');
  return (
    <div className="space-y-8">
      <section className="hero">
        <div className="relative z-10 max-w-2xl">
          <h1 className="text-3xl font-extrabold tracking-tight sm:text-4xl">믿을 수 있는 프리랜서, 안전한 거래</h1>
          <p className="mt-3 text-sm text-white/90 sm:text-base">에스크로로 보호되는 양면 시장. 의뢰인과 전문가를 연결합니다.</p>
          <div className="mt-5 flex gap-3">
            <button className="rounded-xl bg-white px-5 py-2.5 font-semibold text-brand-700 shadow hover:shadow-md" onClick={() => document.getElementById('search')?.focus()}>서비스 찾기</button>
            <button className="rounded-xl bg-white/15 px-5 py-2.5 font-semibold text-white backdrop-blur hover:bg-white/25" onClick={() => setTab('projects')}>프로젝트 탐색</button>
          </div>
        </div>
      </section>

      <div className="flex items-center gap-2 rounded-2xl border bg-white p-1 shadow-sm w-fit">
        <button className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${tab === 'services' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'}`} onClick={() => setTab('services')}>서비스 찾기</button>
        <button className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${tab === 'projects' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'}`} onClick={() => setTab('projects')}>프로젝트 찾기</button>
      </div>
      {tab === 'services' ? <ServiceList /> : <ProjectList />}
    </div>
  );
}

function ServiceList() {
  const { user } = useAuth();
  const [items, setItems] = useState(null);
  const [q, setQ] = useState('');
  const [error, setError] = useState('');

  const load = async (search = '') => {
    try {
      const d = await MarketApi.services(search ? `?q=${encodeURIComponent(search)}` : '');
      setItems(d.services);
    } catch (err) { setError(err.message); }
  };
  useEffect(() => { load(); }, []);

  return (
    <div className="space-y-4">
      <form onSubmit={(e) => { e.preventDefault(); load(q); }} className="flex gap-2">
        <input id="search" className="input flex-1" placeholder="서비스 검색 — 예) 로고 디자인, 코딩" value={q} onChange={(e) => setQ(e.target.value)} />
        <Button type="submit">검색</Button>
      </form>
      <ErrorBox message={error} />
      {!items ? <Spinner /> : items.length === 0 ? <p className="text-slate-400">등록된 서비스가 없습니다.</p> : (
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {items.map((s) => (
            <div key={s.id} className="group card-compact flex flex-col justify-between">
              <div>
                <div className="flex items-start justify-between gap-3">
                  <p className="font-bold text-slate-900">{s.title}</p>
                  <span className="badge bg-brand-50 text-brand-700">인기</span>
                </div>
                <p className="mt-1 text-sm text-slate-500">{s.freelancer_name} · ⭐ {Number(s.rating_avg || 0).toFixed(1)}</p>
                <p className="mt-3 text-xl font-extrabold text-brand-700">{won(s.price)}</p>
                <p className="text-xs text-slate-400">{s.delivery_days}일 작업</p>
              </div>
              <div className="mt-4 flex gap-2">
                <Button variant="muted" onClick={() => navigate(`/services/${s.id}`)}>상세</Button>
                {user?.role === 'client' && (
                  <BuyButton serviceId={s.id} />
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function BuyButton({ serviceId }) {
  const [busy, setBusy] = useState(false);
  const buy = async () => {
    setBusy(true);
    try {
      const d = await OrderApi.create({ service_id: serviceId });
      alert('주문이 생성되었습니다. 주문 목록에서 결제를 진행하세요.');
      navigate('/orders');
      return d;
    } catch (err) { alert(err.message); } finally { setBusy(false); }
  };
  return <Button disabled={busy} onClick={buy}>구매</Button>;
}

function ProjectList() {
  const [items, setItems] = useState(null);
  const [error, setError] = useState('');
  useEffect(() => {
    MarketApi.projects().then((d) => setItems(d.projects)).catch((e) => setError(e.message));
  }, []);
  return (
    <div className="space-y-3">
      <ErrorBox message={error} />
      {!items ? <Spinner /> : items.length === 0 ? <p className="text-slate-400">모집 중인 프로젝트가 없습니다.</p> : items.map((p) => (
        <div key={p.id} className="card-compact flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="min-w-0">
            <p className="font-bold truncate">{p.title}</p>
            <p className="text-sm text-slate-500">{p.client_name} · 예산 {p.budget ? won(p.budget) : '협의'} · 지원 {p.proposal_count}명</p>
          </div>
          <Button variant="muted" onClick={() => navigate(`/projects/${p.id}`)}>상세/지원</Button>
        </div>
      ))}
    </div>
  );
}

/* ================= 서비스 상세 ================= */
export function ServiceDetailPage({ id }) {
  const { user } = useAuth();
  const [service, setService] = useState(null);
  const [portfolios, setPortfolios] = useState([]);
  const [reviews, setReviews] = useState({ reviews: [], average: null, count: 0 });
  const [error, setError] = useState('');

  useEffect(() => {
    MarketApi.service(id).then((d) => {
      setService(d.service);
      PortfolioApi.list(d.service.freelancer_id).then((p) => setPortfolios(p.portfolios)).catch(() => {});
      ReviewApi.byUser(d.service.freelancer_id).then(setReviews).catch(() => {});
    }).catch((e) => setError(e.message));
  }, [id]);

  if (error) return <ErrorBox message={error} />;
  if (!service) return <Spinner />;
  return (
    <div className="space-y-4">
      <Card className="space-y-3">
        <p className="text-xl font-bold">{service.title}</p>
        <p className="text-sm text-slate-500">
          {service.freelancer_name} {service.headline ? `· ${service.headline}` : ''}
          {' '}· ⭐ {Number(reviews.average ?? service.rating_avg ?? 0).toFixed(1)} ({reviews.count || 0})
        </p>
        <p className="text-2xl font-bold text-brand-700">{won(service.price)} <span className="text-sm font-normal text-slate-400">/ {service.delivery_days}일</span></p>
        <p className="whitespace-pre-wrap text-slate-700">{service.description}</p>
        {user?.role === 'client' && <BuyButton serviceId={service.id} />}
      </Card>

      {portfolios.length > 0 && (
        <Card>
          <h3 className="mb-2 font-semibold">포트폴리오</h3>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {portfolios.map((p) => (
              <a key={p.id} href={p.link_url || '#'} target="_blank" rel="noreferrer" className="rounded-lg border p-2 no-underline">
                {p.thumbnail && <img src={`/${p.thumbnail}`} alt="" className="mb-2 h-28 w-full rounded object-cover" />}
                <p className="text-sm font-medium">{p.title}</p>
                {p.description && <p className="text-xs text-slate-500">{p.description}</p>}
              </a>
            ))}
          </div>
        </Card>
      )}

      <Card>
        <h3 className="mb-2 font-semibold">리뷰 ({reviews.count || 0})</h3>
        {reviews.reviews.length === 0 && <p className="text-slate-400">아직 리뷰가 없습니다.</p>}
        {reviews.reviews.map((r) => (
          <div key={r.id} className="border-b py-2 text-sm last:border-0">
            <div className="flex items-center justify-between">
              <p className="font-medium">{r.reviewer_name} · {'★'.repeat(r.rating)}{'☆'.repeat(5 - r.rating)}</p>
              {user && String(user.id) !== String(r.reviewer_id) && (
                <button className="text-xs text-slate-400 hover:text-rose-600"
                  onClick={async () => {
                    const reason = prompt('신고 사유를 입력하세요');
                    if (reason === null) return;
                    try { await ReviewApi.report(r.id, reason); alert('신고가 접수되었습니다.'); }
                    catch (e) { alert(e.message); }
                  }}>신고</button>
              )}
            </div>
            {r.order_title && <p className="text-xs text-slate-400">{r.order_title}</p>}
            <p className="text-slate-600">{r.comment}</p>
          </div>
        ))}
      </Card>
    </div>
  );
}

/* ================= 프로젝트 상세 + 지원/수락 ================= */
export function ProjectDetailPage({ id }) {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [proposal, setProposal] = useState({ price: '', delivery_days: 3, message: '' });

  const load = () => MarketApi.project(id).then(setData).catch((e) => setError(e.message));
  useEffect(() => { load(); /* eslint-disable-next-line */ }, [id]);
  if (error) return <ErrorBox message={error} />;
  if (!data) return <Spinner />;

  const { project, proposals } = data;
  const isOwner = user && user.role === 'client' && String(user.id) === String(project.client_id);

  const submitProposal = async (e) => {
    e.preventDefault(); setError('');
    try {
      await MarketApi.submitProposal(project.id, proposal);
      alert('지원이 완료되었습니다.');
      load();
    } catch (err) { setError(err instanceof ApiError ? err.message : '지원 실패'); }
  };

  const accept = async (proposalId) => {
    if (!confirm('이 지원을 수락하면 주문이 생성됩니다. 진행할까요?')) return;
    try {
      await MarketApi.acceptProposal(proposalId);
      alert('지원을 수락했습니다. 주문 목록에서 결제하세요.');
      navigate('/orders');
    } catch (err) { alert(err.message); }
  };

  return (
    <div className="space-y-4">
      <Card>
        <p className="text-xl font-bold">{project.title}</p>
        <p className="text-sm text-slate-500">{project.client_name} · 예산 {project.budget ? won(project.budget) : '협의'} · 마감 {project.deadline || '-'}</p>
        <p className="mt-2 whitespace-pre-wrap text-slate-700">{project.description}</p>
        {project.attachment && (
          <p className="mt-2 text-sm"><a href={`/${project.attachment}`} target="_blank" rel="noreferrer">📎 첨부파일 다운로드</a></p>
        )}
      </Card>

      {user?.role === 'freelancer' && project.status === 'open' && (
        <form onSubmit={submitProposal} className="card space-y-3">
          <h3 className="font-semibold">지원하기</h3>
          <ErrorBox message={error} />
          <div className="grid grid-cols-2 gap-3">
            <Field label="제안 금액(원)"><input className="input" type="number" value={proposal.price} onChange={(e) => setProposal({ ...proposal, price: e.target.value })} required /></Field>
            <Field label="작업 기간(일)"><input className="input" type="number" value={proposal.delivery_days} onChange={(e) => setProposal({ ...proposal, delivery_days: e.target.value })} /></Field>
          </div>
          <Field label="제안 메모"><textarea className="input" rows="3" value={proposal.message} onChange={(e) => setProposal({ ...proposal, message: e.target.value })} /></Field>
          <Button type="submit">지원 제출</Button>
        </form>
      )}

      <Card>
        <h3 className="mb-2 font-semibold">지원 내역 ({proposals.length})</h3>
        {proposals.length === 0 && <p className="text-slate-400">아직 지원이 없습니다.</p>}
        {proposals.map((p) => (
          <div key={p.id} className="flex items-center justify-between border-b py-2 last:border-0">
            <div>
              <p className="font-medium">{p.freelancer_name} <span className="text-xs text-slate-400">⭐ {Number(p.rating_avg || 0).toFixed(1)}</span></p>
              <p className="text-sm text-slate-500">{won(p.price)} · {p.delivery_days}일 · {p.status}</p>
              {p.message && <p className="text-xs text-slate-500">{p.message}</p>}
            </div>
            {isOwner && p.status === 'submitted' && <Button variant="success" onClick={() => accept(p.id)}>수락</Button>}
          </div>
        ))}
      </Card>
    </div>
  );
}
