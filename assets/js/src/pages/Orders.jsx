import React, { useEffect, useRef, useState } from 'react';
import { OrderApi, ReviewApi, PaymentApi, uploadFile, ApiError } from '../api.js';
import { useAuth } from '../store.jsx';
import { Card, Button, Field, ErrorBox, Spinner, StatusBadge, won, navigate } from '../ui.jsx';

/* ================= 주문 목록 ================= */
export function OrdersPage() {
  const { user } = useAuth();
  const [orders, setOrders] = useState(null);
  const [error, setError] = useState('');
  useEffect(() => { OrderApi.list().then((d) => setOrders(d.orders)).catch((e) => setError(e.message)); }, []);
  if (error) return <ErrorBox message={error} />;
  if (!orders) return <Spinner />;
  if (orders.length === 0) return <p className="text-slate-400">주문이 없습니다.</p>;
  return (
    <div className="space-y-3">
      {orders.map((o) => (
        <Card key={o.id} className="flex items-center justify-between">
          <div>
            <p className="font-semibold">{o.title}</p>
            <p className="text-sm text-slate-500">{o.order_no} · {won(o.amount)}</p>
          </div>
          <div className="flex items-center gap-3">
            <StatusBadge status={o.status} />
            <Button variant="muted" onClick={() => navigate(`/orders/${o.id}`)}>상세</Button>
          </div>
        </Card>
      ))}
    </div>
  );
}

/* ================= 주문 상세 ================= */
export function OrderDetailPage({ id }) {
  const { user } = useAuth();
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const load = () => OrderApi.show(id).then(setData).catch((e) => setError(e.message));
  useEffect(() => { load(); /* eslint-disable-next-line */ }, [id]);

  if (error) return <ErrorBox message={error} />;
  if (!data) return <Spinner />;

  const { order, escrow_transactions, deliverables } = data;
  const isClient = user && String(user.id) === String(order.client_id);
  const isFreelancer = user && String(user.id) === String(order.freelancer_id);

  const act = async (fn, confirmMsg) => {
    if (confirmMsg && !confirm(confirmMsg)) return;
    setBusy(true); setError('');
    try { await fn(); await load(); }
    catch (err) { setError(err instanceof ApiError ? err.message : '처리 실패'); }
    finally { setBusy(false); }
  };

  return (
    <div className="space-y-4">
      <Card className="space-y-2">
        <div className="flex items-center justify-between">
          <p className="text-lg font-bold">{order.title}</p>
          <StatusBadge status={order.status} />
        </div>
        <p className="text-sm text-slate-500">
          {order.order_no} · 결제 {won(order.amount)} · 에스크로 {order.escrow_status}
        </p>
        {order.status === 'completed' && (
          <p className="text-sm text-emerald-700">수수료 {won(order.fee_amount)} / 프리랜서 지급 {won(order.net_amount)}</p>
        )}
        <ErrorBox message={error} />

        <div className="mt-2 flex flex-wrap gap-2">
          {/* 의뢰인 결제 */}
          {isClient && order.status === 'pending_payment' && (
            <PayActions order={order} onDone={load} onError={setError} />
          )}
          {/* 프리랜서 발송 */}
          {isFreelancer && ['in_progress', 'revision_requested'].includes(order.status) && (
            <DeliverBox orderId={order.id} onDone={load} onError={setError} />
          )}
          {/* 의뢰인 최종 승인 */}
          {isClient && order.status === 'delivered' && (
            <Button variant="success" disabled={busy} onClick={() => act(
              () => OrderApi.approve(order.id),
              '최종 승인하면 대금이 정산됩니다. 진행할까요?'
            )}>최종 승인 (정산)</Button>
          )}
          {/* 의뢰인 자체 환불 (작업물 발송 전) */}
          {isClient && order.escrow_status === 'held' && ['pending_payment', 'in_progress'].includes(order.status) && (
            <Button variant="muted" disabled={busy} onClick={() => act(
              () => OrderApi.refund(order.id, prompt('환불 사유') || ''),
              '환불을 요청할까요?'
            )}>환불 요청</Button>
          )}
          {/* 분쟁 제기 (양측) */}
          {(isClient || isFreelancer) && ['in_progress', 'delivered', 'revision_requested'].includes(order.status) && (
            <Button variant="muted" disabled={busy} onClick={() => act(
              () => OrderApi.dispute(order.id, prompt('분쟁 사유를 입력하세요') || ''),
              '분쟁을 제기할까요? 관리자 검토 후 처리됩니다.'
            )}>분쟁 제기</Button>
          )}
        </div>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <h3 className="mb-2 font-semibold">작업물</h3>
          {deliverables.length === 0 && <p className="text-slate-400">아직 제출된 작업물이 없습니다.</p>}
          {deliverables.map((d) => (
            <div key={d.id} className="border-b py-2 text-sm last:border-0">
              <p className="font-medium">v{d.version} · {d.created_at}</p>
              <p className="text-slate-600">{d.message}</p>
              {d.file_path && <a href={`/${d.file_path}`} target="_blank" rel="noreferrer">첨부 다운로드</a>}
            </div>
          ))}
        </Card>

        <Card>
          <h3 className="mb-2 font-semibold">에스크로 원장</h3>
          <table className="w-full text-sm">
            <tbody>
              {escrow_transactions.map((t) => (
                <tr key={t.id} className="border-b last:border-0">
                  <td className="py-1">{t.type}</td>
                  <td className="py-1 text-slate-500">{t.direction === 'in' ? '유입' : '유출'}</td>
                  <td className="py-1 text-right">{won(t.amount)}</td>
                  <td className="py-1 text-xs text-slate-400">{t.created_at}</td>
                </tr>
              ))}
              {escrow_transactions.length === 0 && <tr><td className="py-2 text-slate-400">내역 없음</td></tr>}
            </tbody>
          </table>
        </Card>
      </div>

      {order.status === 'completed' && <ReviewBox order={order} user={user} />}

      <ChatBox orderId={order.id} />
    </div>
  );
}

/* ================= 작업물 발송 (파일 첨부) ================= */
function DeliverBox({ orderId, onDone, onError }) {
  const [open, setOpen] = useState(false);
  const [message, setMessage] = useState('');
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true); onError('');
    try {
      let filePath;
      if (file) filePath = (await uploadFile(file, 'deliverable')).path;
      await OrderApi.deliver(orderId, { message: message || '작업물을 전달합니다.', file_path: filePath });
      setOpen(false); setMessage(''); setFile(null);
      await onDone();
    } catch (err) { onError(err instanceof ApiError ? err.message : '발송 실패'); }
    finally { setBusy(false); }
  };

  if (!open) return <Button onClick={() => setOpen(true)}>작업물 발송</Button>;
  return (
    <div className="mt-2 w-full rounded-lg border p-3">
      <textarea className="input mb-2" rows="2" placeholder="작업물 설명" value={message} onChange={(e) => setMessage(e.target.value)} />
      <input type="file" className="mb-2 text-sm" onChange={(e) => setFile(e.target.files[0])} />
      <div className="flex gap-2">
        <Button variant="success" disabled={busy} onClick={submit}>발송</Button>
        <Button variant="muted" disabled={busy} onClick={() => setOpen(false)}>취소</Button>
      </div>
    </div>
  );
}

/* ================= 리뷰 작성 / 조회 ================= */
function ReviewBox({ order, user }) {
  const [reviews, setReviews] = useState([]);
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState('');
  const [error, setError] = useState('');

  const load = () => ReviewApi.forOrder(order.id).then((d) => setReviews(d.reviews)).catch((e) => setError(e.message));
  useEffect(() => { load(); /* eslint-disable-next-line */ }, [order.id]);

  const mine = reviews.find((r) => String(r.reviewer_id) === String(user.id));

  const submit = async (e) => {
    e.preventDefault(); setError('');
    try { await ReviewApi.create(order.id, rating, comment); load(); }
    catch (err) { setError(err.message); }
  };

  return (
    <Card>
      <h3 className="mb-2 font-semibold">리뷰</h3>
      <ErrorBox message={error} />
      {reviews.map((r) => (
        <div key={r.id} className="border-b py-2 text-sm last:border-0">
          <div className="flex items-center justify-between">
            <p className="font-medium">{r.reviewer_name} · {'★'.repeat(r.rating)}{'☆'.repeat(5 - r.rating)}</p>
            {String(user.id) !== String(r.reviewer_id) && (
              <button className="text-xs text-slate-400 hover:text-rose-600"
                onClick={async () => {
                  const reason = prompt('신고 사유를 입력하세요');
                  if (reason === null) return;
                  try { await ReviewApi.report(r.id, reason); alert('신고가 접수되었습니다.'); }
                  catch (e) { alert(e.message); }
                }}>신고</button>
            )}
          </div>
          <p className="text-slate-600">{r.comment}</p>
        </div>
      ))}
      {!mine ? (
        <form onSubmit={submit} className="mt-3 space-y-2">
          <select className="input" value={rating} onChange={(e) => setRating(Number(e.target.value))}>
            {[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{'★'.repeat(n)} ({n}점)</option>)}
          </select>
          <textarea className="input" rows="2" placeholder="후기를 남겨주세요" value={comment} onChange={(e) => setComment(e.target.value)} />
          <Button type="submit">리뷰 등록</Button>
        </form>
      ) : (
        <p className="mt-2 text-xs text-slate-400">이미 리뷰를 작성했습니다.</p>
      )}
    </Card>
  );
}

/* ================= 결제 (수동 / Stripe) ================= */
function PayActions({ order, onDone, onError }) {
  const [busy, setBusy] = useState(false);
  const [stripeReady, setStripeReady] = useState(null); // null=미확인, false=미설정
  const [methods, setMethods] = useState(null);

  useEffect(() => {
    // Stripe 키가 설정되어 있는지 확인 (PaymentIntent 생성 시도는 지연)
    OrderApi.intent(order.id).then((d) => setStripeReady(d)).catch(() => setStripeReady(false));
    PaymentApi.methods().then(setMethods).catch(() => setMethods({}));
  }, [order.id]);

  const manualPay = async () => {
    setBusy(true); onError('');
    try { await OrderApi.pay(order.id, { pg_provider: 'manual', amount: order.amount }); await onDone(); }
    catch (err) { onError(err.message); } finally { setBusy(false); }
  };

  return (
    <>
      <Button disabled={busy} onClick={manualPay}>결제(예치)</Button>
      {stripeReady && stripeReady.client_secret && (
        <StripePay intent={stripeReady} onPaid={onDone} onError={onError} />
      )}
      {methods?.toss?.enabled && (
        <TossPay order={order} clientKey={methods.toss.client_key} onError={onError} />
      )}
    </>
  );
}

function loadTossSdk() {
  return new Promise((resolve) => {
    if (window.TossPayments) return resolve(window.TossPayments);
    const s = document.createElement('script');
    s.src = 'https://js.tosspayments.com/v2/standard';
    s.onload = () => resolve(window.TossPayments);
    s.onerror = () => resolve(null);
    document.head.appendChild(s);
  });
}

function TossPay({ order, clientKey, onError }) {
  const [busy, setBusy] = useState(false);
  const pay = async () => {
    setBusy(true); onError('');
    try {
      const TossPayments = await loadTossSdk();
      if (!TossPayments) throw new Error('토스페이먼츠 SDK를 불러올 수 없습니다.');
      const tossPayments = TossPayments(clientKey);
      const payment = tossPayments.payment({ customerKey: 'order-' + order.order_no });
      await payment.requestPayment({
        method: 'CARD',
        amount: { currency: 'KRW', value: Number(order.amount) },
        orderId: order.order_no,
        orderName: order.title,
        successUrl: `${location.origin}${location.pathname}#/orders/${order.id}/toss-success`,
        failUrl: `${location.origin}${location.pathname}#/orders/${order.id}/toss-fail`,
      });
    } catch (err) { onError(err.message); setBusy(false); }
  };
  return <Button variant="success" disabled={busy} onClick={pay}>토스페이먼츠 결제</Button>;
}

function loadStripeJs() {
  return new Promise((resolve) => {
    if (window.Stripe) return resolve(window.Stripe);
    const s = document.createElement('script');
    s.src = 'https://js.stripe.com/v3/';
    s.onload = () => resolve(window.Stripe);
    s.onerror = () => resolve(null);
    document.head.appendChild(s);
  });
}

function StripePay({ intent, onPaid, onError }) {
  const [open, setOpen] = useState(false);
  const [client, setClient] = useState(null);
  const [elements, setElements] = useState(null);
  const cardRef = useRef(null);
  const [busy, setBusy] = useState(false);

  const start = async () => {
    setOpen(true);
    const Stripe = await loadStripeJs();
    if (!Stripe) { onError('Stripe.js 를 불러올 수 없습니다.'); return; }
    const stripe = Stripe(intent.publishable_key);
    const els = stripe.elements();
    const card = els.create('card');
    card.mount('#stripe-card-element');
    setClient(stripe); setElements(els); cardRef.current = card;
  };

  const confirm = async () => {
    if (!client || !cardRef.current) return;
    setBusy(true); onError('');
    try {
      const { error, paymentIntent } = await client.confirmCardPayment(intent.client_secret, {
        payment_method: { card: cardRef.current },
      });
      if (error) throw new Error(error.message);
      if (paymentIntent && paymentIntent.status === 'succeeded') {
        alert('결제가 완료되었습니다. 에스크로 예치 처리 중입니다.');
        await onPaid();
      }
    } catch (err) { onError(err.message); } finally { setBusy(false); }
  };

  return (
    <div className="w-full">
      {!open ? (
        <Button variant="success" onClick={start}>카드 결제 (Stripe)</Button>
      ) : (
        <div className="mt-2 rounded-lg border p-3">
          <div id="stripe-card-element" className="mb-3 rounded border p-2" />
          <Button variant="success" disabled={busy || !elements} onClick={confirm}>결제 확인</Button>
        </div>
      )}
    </div>
  );
}

/* ================= 토스 결제 결과 처리 ================= */
export function TossResultPage({ orderId, query }) {
  const [msg, setMsg] = useState('결제를 확인하는 중…');
  const [error, setError] = useState('');

  useEffect(() => {
    const code = query.get('code');
    const paymentKey = query.get('paymentKey');
    const amount = query.get('amount');

    if (code) {
      setError('결제가 취소/실패했습니다: ' + (query.get('message') || code));
      setMsg('');
      return;
    }
    if (!paymentKey || !amount) {
      setError('결제 파라미터가 없습니다.');
      setMsg('');
      return;
    }
    OrderApi.tossConfirm(orderId, paymentKey, amount)
      .then(() => { setMsg('결제가 완료되어 에스크로에 예치되었습니다.'); setTimeout(() => navigate(`/orders/${orderId}`), 1200); })
      .catch((e) => { setError(e.message); setMsg(''); });
    // eslint-disable-next-line
  }, []);

  return (
    <Card className="mx-auto mt-16 max-w-md space-y-3 p-8 text-center">
      {msg && <p className="text-slate-600">{msg}</p>}
      {error && <p className="badge block bg-red-50 text-red-600">{error}</p>}
      <Button variant="muted" onClick={() => navigate(`/orders/${orderId}`)}>주문으로 이동</Button>
    </Card>
  );
}

/* ================= 채팅 ================= */
function ChatBox({ orderId }) {
  const [messages, setMessages] = useState([]);
  const [text, setText] = useState('');
  const [error, setError] = useState('');

  const load = () => OrderApi.messages(orderId).then((d) => setMessages(d.messages)).catch((e) => setError(e.message));
  useEffect(() => { load(); /* eslint-disable-next-line */ }, [orderId]);

  const send = async (e) => {
    e.preventDefault();
    if (!text.trim()) return;
    try { await OrderApi.sendMessage(orderId, text); setText(''); load(); }
    catch (err) { setError(err.message); }
  };

  return (
    <Card>
      <h3 className="mb-2 font-semibold">메시지</h3>
      <ErrorBox message={error} />
      <div className="mb-3 max-h-64 space-y-2 overflow-y-auto">
        {messages.map((m) => (
          <div key={m.id} className="rounded bg-slate-50 p-2 text-sm">
            <span className="font-medium">{m.sender_name}</span>
            <span className="ml-2 text-xs text-slate-400">{m.created_at}</span>
            <p>{m.content}</p>
          </div>
        ))}
        {messages.length === 0 && <p className="text-slate-400">메시지가 없습니다.</p>}
      </div>
      <form onSubmit={send} className="flex gap-2">
        <input className="input" placeholder="메시지 입력" value={text} onChange={(e) => setText(e.target.value)} />
        <Button type="submit">전송</Button>
      </form>
    </Card>
  );
}
