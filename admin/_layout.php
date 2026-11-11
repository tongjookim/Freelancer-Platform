<?php
declare(strict_types=1);

/**
 * 관리자 레이아웃 (좌측 사이드바 + 본문)
 * - 페이지: admin_header('제목', '활성메뉴키') ... admin_footer()
 * - meta 로 CSRF 토큰/API base 주입 (관리자 액션 JS에서 사용)
 * - 본문 컨테이너에 [&_.card] 유틸리티로 카드 여백/라운드를 일괄 확대
 */

function admin_header(string $title, string $active = ''): void
{
    $user = current_user();
    // key => [href, label, icon(svg path d)]
    $nav = [
        'dashboard' => ['/admin/index.php',    '대시보드',   'M3 12l9-9 9 9M5 10v10h14V10'],
        'orders'    => ['/admin/orders.php',   '주문·결제',  'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        'disputes'  => ['/admin/disputes.php', '분쟁 관리',  'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z'],
        'reviews'   => ['/admin/reviews.php',  '리뷰 관리',  'M11.05 3.7l2.29 4.64 5.12.74-3.7 3.61.87 5.1-4.58-2.41-4.58 2.41.87-5.1-3.7-3.61 5.12-.74z'],
        'stats'     => ['/admin/stats.php',    '통계',       'M4 20V10M10 20V4M16 20v-7M22 20H2'],
        'finance'   => ['/admin/finance.php',  '정산 관리',  'M2 7h20v10H2zM6 12h.01M18 12h.01M12 14a2 2 0 100-4 2 2 0 000 4z'],
        'users'     => ['/admin/users.php',    '회원 관리',  'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
        'settings'  => ['/admin/settings.php', '수수료 설정', 'M4 6h16M4 12h16M4 18h16M8 4v4M16 10v4M8 16v4'],
    ];
    ?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> · 관리자</title>
  <meta name="api-base" content="<?= h(API_BASE) ?>">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <link rel="stylesheet" href="/assets/css/default.css?v=<?= filemtime(BASE_PATH . '/assets/css/default.css') ?>">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

  <!-- 모바일 오버레이 -->
  <div id="admin-overlay" class="fixed inset-0 z-30 hidden bg-slate-900/40 backdrop-blur-sm lg:hidden"></div>

  <!-- 좌측 사이드바 -->
  <aside id="admin-sidebar"
         class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 ease-out lg:translate-x-0">
    <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-slate-100 px-6">
      <span class="h-8 w-8 rounded-xl bg-gradient-to-br from-brand-600 to-indigo-600"></span>
      <a href="/admin/index.php" class="font-extrabold tracking-tight text-slate-900 no-underline">프리랜서 마켓</a>
    </div>

    <nav class="flex-1 space-y-1.5 overflow-y-auto px-4 py-6">
      <?php foreach ($nav as $key => [$href, $label, $icon]): ?>
        <a href="<?= h($href) ?>"
           class="group flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm no-underline transition <?= $key === $active
               ? 'bg-brand-50 font-semibold text-brand-700'
               : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
          <svg class="h-5 w-5 shrink-0 <?= $key === $active ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-600' ?>"
               viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="<?= h($icon) ?>"/>
          </svg>
          <span><?= h($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="border-t border-slate-100 p-4">
      <div class="rounded-xl bg-slate-50 px-4 py-3">
        <p class="truncate text-sm font-medium text-slate-700"><?= h($user['name'] ?? '') ?> 관리자</p>
        <a href="/admin/logout.php" class="mt-0.5 inline-block text-xs text-slate-500 hover:text-slate-700 hover:underline">로그아웃</a>
      </div>
    </div>
  </aside>

  <!-- 본문 영역 -->
  <div class="flex min-h-screen flex-col lg:pl-64">
    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/80 backdrop-blur">
      <div class="flex h-16 items-center gap-3 px-6 lg:px-10">
        <button type="button" id="admin-menu-toggle" aria-label="메뉴"
                class="rounded-xl p-2 text-slate-600 hover:bg-slate-100 lg:hidden">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="text-lg font-bold tracking-tight"><?= h($title) ?></h1>
      </div>
    </header>

    <main class="flex-1 px-6 py-8 lg:px-10 lg:py-12">
      <div id="admin-flash" class="mb-6 hidden rounded-xl px-4 py-3 text-sm"></div>
      <div class="mx-auto max-w-6xl space-y-6 [&_.card]:rounded-2xl [&_.card]:p-6 lg:[&_.card]:p-7">
<?php
}

function admin_footer(): void
{
    ?>
      </div>
    </main>

    <footer class="border-t border-slate-200 bg-white/60 px-6 py-6 text-center text-xs text-slate-400 lg:px-10">
      &copy; <?= date('Y') ?> 프리랜서 마켓 관리자
    </footer>
  </div>

  <script>
    /** 사이드바 토글 (모바일) */
    (function () {
      const sidebar = document.getElementById('admin-sidebar');
      const overlay = document.getElementById('admin-overlay');
      const toggle  = document.getElementById('admin-menu-toggle');
      const open = () => { sidebar.classList.remove('-translate-x-full'); overlay.classList.remove('hidden'); };
      const close = () => { sidebar.classList.add('-translate-x-full'); overlay.classList.add('hidden'); };
      toggle && toggle.addEventListener('click', open);
      overlay && overlay.addEventListener('click', close);
    })();

    /** 관리자 API 호출 헬퍼: CSRF/세션 자동 처리 */
    async function adminApi(method, path, body) {
      const base  = document.querySelector('meta[name="api-base"]').content;
      const token = document.querySelector('meta[name="csrf-token"]').content;
      const res = await fetch(base + path, {
        method,
        credentials: 'include',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: body ? JSON.stringify(body) : undefined,
      });
      const payload = await res.json().catch(() => ({}));
      if (!res.ok || payload.success === false) {
        throw new Error(payload && payload.error ? payload.error.message : ('요청 실패 (' + res.status + ')'));
      }
      return payload.data;
    }

    /** 알림 표시 */
    function adminFlash(message, ok = true) {
      const el = document.getElementById('admin-flash');
      el.textContent = message;
      el.className = 'mb-6 rounded-xl px-4 py-3 text-sm ' +
        (ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600');
      el.classList.remove('hidden');
      setTimeout(() => el.classList.add('hidden'), 4000);
    }
  </script>
</body>
</html>
<?php
}
