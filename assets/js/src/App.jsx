import React, { useEffect, useState } from 'react';
import { useAuth } from './store.jsx';
import { useRoute, Spinner, navigate } from './ui.jsx';
import { NotificationApi } from './api.js';
import AuthPage from './pages/Auth.jsx';
import MarketPage, { ServiceDetailPage, ProjectDetailPage } from './pages/Market.jsx';
import { OrdersPage, OrderDetailPage, TossResultPage } from './pages/Orders.jsx';
import WorkspacePage from './pages/Workspace.jsx';
import ProfilePage from './pages/Profile.jsx';

const NAV = [
  ['/', '마켓'],
  ['/orders', '주문'],
  ['/workspace', '마이 워크스페이스'],
  ['/profile', '프로필'],
];

/** 로그인이 필요한 경로 */
const PROTECTED = ['/orders', '/workspace', '/profile'];

function NavLink({ to, label, active, onClick }) {
  return (
    <button onClick={() => { navigate(to); onClick?.(); }}
      className={`nav-link ${active ? 'nav-link-active' : ''}`}>
      {label}
    </button>
  );
}

export default function App() {
  const { user, ready, logout } = useAuth();
  const { seg, query } = useRoute();
  const root = '/' + (seg[0] || '');
  const [mobileOpen, setMobileOpen] = React.useState(false);

  // ---- 라우팅 -------------------------------------------------------------
  const needsAuth = PROTECTED.includes(root);
  let page;
  if (!ready) {
    page = <Spinner />;
  } else if (seg[0] === 'login' || (!user && needsAuth)) {
    page = <AuthPage />;
  } else if (seg[0] === 'services' && seg[1]) {
    page = <ServiceDetailPage id={seg[1]} />;
  } else if (seg[0] === 'projects' && seg[1]) {
    page = <ProjectDetailPage id={seg[1]} />;
  } else if (seg[0] === 'orders' && (seg[2] === 'toss-success' || seg[2] === 'toss-fail')) {
    page = <TossResultPage orderId={seg[1]} query={query} />;
  } else if (seg[0] === 'orders' && seg[1]) {
    page = <OrderDetailPage id={seg[1]} />;
  } else if (seg[0] === 'orders') {
    page = <OrdersPage />;
  } else if (seg[0] === 'workspace') {
    page = <WorkspacePage />;
  } else if (seg[0] === 'profile') {
    page = <ProfilePage />;
  } else {
    page = <MarketPage />;
  }

  return (
    <div className="min-h-screen flex flex-col">
      <header className="sticky top-0 z-40 border-b border-slate-200 bg-white/80 backdrop-blur supports-[backdrop-filter]:bg-white/60">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
          <button onClick={() => navigate('/')} className="flex items-center gap-2">
            <img src="/assets/img/logo.svg" alt="logo" className="h-8 w-8" />
            <span className="text-xl font-extrabold tracking-tight text-slate-900">프리랜서 마켓</span>
          </button>

          <nav className="hidden items-center gap-1 md:flex">
            {NAV.map(([to, label]) => <NavLink key={to} to={to} label={label} active={root === to} />)}
          </nav>

          <div className="flex items-center gap-2">
            <div className="hidden items-center gap-3 text-sm md:flex">
              {user ? (
                <>
                  <NotificationBell />
                  <span className="rounded-full bg-slate-100 px-3 py-1 text-slate-600">{user.name}</span>
                  <button className="btn-muted" onClick={logout}>로그아웃</button>
                </>
              ) : (
                <button className="btn-primary" onClick={() => navigate('/login')}>로그인 / 가입</button>
              )}
            </div>
            <button className="md:hidden rounded-xl p-2 hover:bg-slate-100" onClick={() => setMobileOpen(v => !v)} aria-label="메뉴">
              <svg width="24" height="24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
          </div>
        </div>
        {mobileOpen && (
          <div className="border-t border-slate-200 bg-white md:hidden">
            <div className="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-3">
              {NAV.map(([to, label]) => <NavLink key={to} to={to} label={label} active={root === to} onClick={() => setMobileOpen(false)} />)}
              <div className="mt-2 flex items-center gap-2">
                {user ? (
                  <>
                    <span className="text-sm text-slate-600">{user.name}</span>
                    <button className="btn-muted flex-1" onClick={() => { logout(); setMobileOpen(false); }}>로그아웃</button>
                  </>
                ) : (
                  <button className="btn-primary w-full" onClick={() => { navigate('/login'); setMobileOpen(false); }}>로그인 / 가입</button>
                )}
              </div>
            </div>
          </div>
        )}
      </header>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">{page}</main>

      <footer className="border-t border-slate-200 bg-white/70 py-10 text-center text-xs text-slate-500 backdrop-blur">
        <div className="mx-auto max-w-6xl px-4">
          <p>&copy; {new Date().getFullYear()} 프리랜서 마켓 · 에스크로 기반 안전 거래 플랫폼</p>
          <p className="mt-1">의뢰인과 프리랜서를 연결하는 신뢰 있는 마켓플레이스</p>
          <p className="mt-2">
            <a href="/terms.php" className="hover:underline">이용약관</a> ·
            <a href="/seller-terms.php" className="hover:underline">판매자 이용약관</a> ·
            <a href="/privacy.php" className="hover:underline">개인정보 처리방침</a>
          </p>
        </div>
      </footer>
    </div>
  );
}

/* ================= 인앱 알림 벨 ================= */
function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);

  const load = () => NotificationApi.list()
    .then((d) => { setItems(d.notifications); setUnread(d.unread); })
    .catch(() => {});

  useEffect(() => {
    load();
    const t = setInterval(load, 30000);
    return () => clearInterval(t);
  }, []);

  const openItem = async (n) => {
    if (!n.is_read) { try { await NotificationApi.read(n.id); } catch (_) {} }
    setOpen(false);
    if (n.link) navigate(n.link);
    load();
  };

  return (
    <div className="relative">
      <button onClick={() => { setOpen((v) => !v); load(); }} className="relative rounded-xl p-2 hover:bg-slate-100" aria-label="알림">
        <svg width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/></svg>
        {unread > 0 && <span className="absolute -right-0.5 -top-0.5 rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white">{unread}</span>}
      </button>
      {open && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <div className="absolute right-0 z-50 mt-2 w-80 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl">
            <div className="flex items-center justify-between px-2 py-1">
              <span className="text-sm font-semibold">알림</span>
              <button className="text-xs text-brand-600" onClick={async () => { try { await NotificationApi.readAll(); } catch (_) {} load(); }}>모두 읽음</button>
            </div>
            <div className="max-h-80 overflow-y-auto">
              {items.length === 0 && <p className="px-2 py-4 text-center text-sm text-slate-400">알림이 없습니다.</p>}
              {items.map((n) => (
                <button key={n.id} onClick={() => openItem(n)}
                  className={`block w-full rounded-xl px-3 py-2 text-left hover:bg-slate-50 ${n.is_read ? '' : 'bg-brand-50'}`}>
                  <p className="text-sm font-medium text-slate-800">{n.title}</p>
                  {n.body && <p className="mt-0.5 text-xs text-slate-500">{n.body}</p>}
                  <p className="mt-1 text-[10px] text-slate-400">{n.created_at}</p>
                </button>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
