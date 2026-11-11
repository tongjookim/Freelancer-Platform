/**
 * 공용 UI 프리미티브 + 초경량 해시 라우터 + 표시 상수
 * (Tailwind 컴포넌트 클래스는 assets/css/src/input.css 에 정의)
 */
import React, { useEffect, useState, createContext, useContext } from 'react';

/* ---------------- 해시 라우터 ---------------- */
export function useRoute() {
  const [hash, setHash] = useState(() => (window.location.hash.slice(1) || '/'));
  useEffect(() => {
    const onChange = () => setHash(window.location.hash.slice(1) || '/');
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  const parts = hash.split('/').filter(Boolean); // 예: ['orders','3'] (쿼리는 parts[last]에 포함될 수 있음)
  const [seg, query] = (() => {
    const [p, q] = hash.split('?');
    const s = p.split('/').filter(Boolean);
    const qs = new URLSearchParams(q || '');
    return [s, qs];
  })();
  return { hash, parts, seg, query };
}

export function navigate(to) {
  window.location.hash = to.startsWith('/') ? to : '/' + to;
}

/* ---------------- 표시 상수 ---------------- */
export const ORDER_STATUS = {
  pending_payment:    '결제 대기',
  in_progress:        '작업 진행 중',
  delivered:          '검수 대기',
  revision_requested: '수정 요청',
  completed:          '완료(정산됨)',
  disputed:           '분쟁 진행 중',
  partially_refunded: '부분 환불',
  refunded:           '환불됨',
  cancelled:          '취소',
};

const BADGE = {
  pending_payment:    'bg-amber-100 text-amber-700',
  in_progress:        'bg-blue-100 text-blue-700',
  delivered:          'bg-indigo-100 text-indigo-700',
  revision_requested: 'bg-orange-100 text-orange-700',
  completed:          'bg-emerald-100 text-emerald-700',
  disputed:           'bg-rose-100 text-rose-700',
  partially_refunded: 'bg-rose-100 text-rose-700',
  refunded:           'bg-slate-200 text-slate-600',
  cancelled:          'bg-slate-100 text-slate-500',
};

export const won = (n) => Number(n || 0).toLocaleString() + '원';

/* ---------------- 컴포넌트 ---------------- */
export function Card({ className = '', children }) {
  return <div className={`card ${className}`}>{children}</div>;
}

export function Button({ variant = 'primary', className = '', ...props }) {
  const v = { primary: 'btn-primary', success: 'btn-success', muted: 'btn-muted' }[variant] || 'btn-primary';
  return <button className={`${v} ${className}`} {...props} />;
}

export function StatusBadge({ status }) {
  return <span className={`badge ${BADGE[status] || 'bg-slate-100 text-slate-600'}`}>{ORDER_STATUS[status] || status}</span>;
}

export function Spinner({ label = '불러오는 중…' }) {
  return <p className="p-10 text-center text-slate-400">{label}</p>;
}

export function ErrorBox({ message }) {
  if (!message) return null;
  return <p className="badge mb-3 block bg-red-50 text-red-600">{message}</p>;
}

export function Field({ label, children, hint }) {
  return (
    <label className="block text-sm font-medium text-slate-600">
      {label}
      <div className="mt-1">{children}</div>
      {hint && <span className="mt-1 block text-xs text-slate-400">{hint}</span>}
    </label>
  );
}
