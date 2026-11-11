/**
 * API 클라이언트
 * - PHP REST 엔드포인트(/api/*)와 통신하는 공통 fetch 래퍼
 * - 세션 쿠키(credentials:'include') + CSRF 헤더 자동 처리
 * - 응답 규약: { success:true, data } | { success:false, error:{message} }
 *
 * 번들링 결과는 /assets/js/script.js 로 출력된다(vite.config.js 참고).
 */

const API_BASE = import.meta.env?.VITE_API_BASE || '/api';

let csrfToken = null;
export function setCsrfToken(token) {
  csrfToken = token;
}

/** APIError: 서버가 내려준 상태코드/메시지를 담는다. */
export class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

async function request(method, path, body) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (csrfToken && !['GET', 'HEAD'].includes(method)) {
    headers['X-CSRF-Token'] = csrfToken;
  }

  const res = await fetch(`${API_BASE}${path}`, {
    method,
    headers,
    credentials: 'include', // 세션 쿠키 전송/수신
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const payload = await res.json().catch(() => ({}));
  if (!res.ok || payload.success === false) {
    throw new ApiError(payload?.error?.message || `요청 실패 (${res.status})`, res.status);
  }
  return payload.data;
}

export const api = {
  get:  (path)        => request('GET', path),
  post: (path, body)  => request('POST', path, body ?? {}),
  put:  (path, body)  => request('PUT', path, body ?? {}),
  del:  (path)        => request('DELETE', path),
};

/* ---------------------------------------------------------------------------
 * 도메인별 헬퍼 (엔드포인트와 1:1 대응)
 * ------------------------------------------------------------------------- */
export const AuthApi = {
  me:             ()           => api.get('/auth/me'),
  login:          (email, pw)  => api.post('/auth/login', { email, password: pw }),
  register:       (payload)    => api.post('/auth/register', payload),
  logout:         ()           => api.post('/auth/logout'),
  changePassword: (current, next) => api.post('/auth/password', { current_password: current, new_password: next }),
};

export const MarketApi = {
  categories:      ()        => api.get('/categories'),
  services:        (query='')=> api.get(`/services${query}`),
  service:         (id)      => api.get(`/services/${id}`),
  projects:        (query='')=> api.get(`/projects${query}`),
  project:         (id)      => api.get(`/projects/${id}`),
  createService:   (payload) => api.post('/services', payload),
  createProject:   (payload) => api.post('/projects', payload),
  submitProposal:  (projectId, payload) => api.post(`/projects/${projectId}/proposals`, payload),
  acceptProposal:  (proposalId)         => api.post(`/proposals/${proposalId}/accept`),
};

export const OrderApi = {
  list:     ()           => api.get('/orders'),
  show:     (id)         => api.get(`/orders/${id}`),
  create:   (payload)    => api.post('/orders', payload),
  pay:      (id, payload)=> api.post(`/orders/${id}/pay`, payload),
  intent:   (id)         => api.post(`/orders/${id}/intent`),      // Stripe PaymentIntent
  tossConfirm: (id, paymentKey, amount) => api.post(`/orders/${id}/toss/confirm`, { payment_key: paymentKey, amount }),
  deliver:  (id, payload)=> api.post(`/orders/${id}/deliver`, payload),
  approve:  (id)         => api.post(`/orders/${id}/approve`),
  refund:   (id, reason) => api.post(`/orders/${id}/refund`, { reason }),
  dispute:  (id, reason) => api.post(`/orders/${id}/dispute`, { reason }),
  messages: (id)         => api.get(`/orders/${id}/messages`),
  sendMessage: (id, content) => api.post(`/orders/${id}/messages`, { content }),
};

/* 파일 업로드 (multipart) — JSON.stringify 하지 않도록 별도 처리 */
export async function uploadFile(file, purpose = 'message') {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('purpose', purpose);
  const headers = { Accept: 'application/json' };
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
  const res = await fetch(`${API_BASE}/uploads`, {
    method: 'POST', headers, credentials: 'include', body: fd,
  });
  const payload = await res.json().catch(() => ({}));
  if (!res.ok || payload.success === false) {
    throw new ApiError(payload?.error?.message || `업로드 실패 (${res.status})`, res.status);
  }
  return payload.data;
}

export const PortfolioApi = {
  list:   (freelancerId) => api.get(`/portfolios?freelancer_id=${freelancerId}`),
  create: (payload)      => api.post('/portfolios', payload),
  remove: (id)           => api.del(`/portfolios/${id}`),
};

export const ReviewApi = {
  forOrder: (orderId) => api.get(`/orders/${orderId}/reviews`),
  create:   (orderId, rating, comment) => api.post(`/orders/${orderId}/reviews`, { rating, comment }),
  byUser:   (userId)  => api.get(`/reviews?user_id=${userId}`),
  report:   (reviewId, reason) => api.post(`/reviews/${reviewId}/report`, { reason }),
};

export const PaymentApi = {
  methods: () => api.get('/payments/methods'),
};

export const NotificationApi = {
  list:    ()   => api.get('/notifications'),
  read:    (id) => api.post(`/notifications/${id}/read`),
  readAll: ()   => api.post('/notifications/read-all'),
};

export const AdminApi = {
  dashboard:   ()          => api.get('/admin/dashboard'),
  settings:    ()          => api.get('/admin/settings'),
  updateSettings: (settings) => api.put('/admin/settings', { settings }),
  orders:      (query='')  => api.get(`/admin/orders${query}`),
  settlements: (query='')  => api.get(`/admin/settlements${query}`),
  payout:      (id)        => api.post(`/admin/settlements/${id}/payout`),
};
