<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

// 이미 관리자면 대시보드로
$u = current_user();
if ($u && $u['role'] === 'admin') {
    header('Location: /admin/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>관리자 로그인</title>
  <meta name="api-base" content="<?= h(API_BASE) ?>">
  <link rel="stylesheet" href="/assets/css/default.css?v=<?= filemtime(BASE_PATH . '/assets/css/default.css') ?>">
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100">
  <form id="login-form" class="card w-full max-w-sm space-y-4 p-8">
    <h1 class="text-xl font-bold text-brand-700">관리자 로그인</h1>
    <p id="msg" class="hidden badge"></p>

    <label class="block text-sm font-medium text-slate-600">이메일
      <input id="email" type="email" class="input mt-1" autocomplete="username" required>
    </label>
    <label class="block text-sm font-medium text-slate-600">비밀번호
      <input id="password" type="password" class="input mt-1" autocomplete="current-password" required>
    </label>

    <button type="submit" class="btn-primary w-full">로그인</button>
  </form>

  <script>
    const form = document.getElementById('login-form');
    const msg  = document.getElementById('msg');
    const base = document.querySelector('meta[name="api-base"]').content;

    function show(text, ok) {
      msg.textContent = text;
      msg.className = 'badge ' + (ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600');
      msg.classList.remove('hidden');
    }

    // 세션을 만들고 CSRF 토큰을 발급받는다(로그인 POST 에 필요)
    let csrfToken = null;
    (async function init() {
      try {
        const res = await fetch(base + '/auth/csrf', { credentials: 'include', headers: { 'Accept': 'application/json' } });
        const payload = await res.json();
        csrfToken = payload.data.csrf_token;
      } catch (_) { /* 토큰 없으면 로그인 시 아래에서 오류 표시 */ }
    })();

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        if (!csrfToken) {
          const r = await fetch(base + '/auth/csrf', { credentials: 'include', headers: { 'Accept': 'application/json' } });
          csrfToken = (await r.json()).data.csrf_token;
        }
        const res = await fetch(base + '/auth/login', {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify({
            email: document.getElementById('email').value,
            password: document.getElementById('password').value,
          }),
        });
        const payload = await res.json().catch(() => ({}));
        if (!res.ok || payload.success === false) {
          throw new Error(payload && payload.error ? payload.error.message : '로그인 실패');
        }
        if (payload.data.user.role !== 'admin') {
          // 관리자가 아니면 세션을 정리하고 거부
          await fetch(base + '/auth/logout', { method: 'POST', credentials: 'include' }).catch(() => {});
          throw new Error('관리자 권한이 없는 계정입니다.');
        }
        show('로그인 성공, 이동합니다…', true);
        location.href = '/admin/index.php';
      } catch (err) {
        show(err.message, false);
      }
    });
  </script>
</body>
</html>
