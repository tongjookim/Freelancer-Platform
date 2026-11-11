<?php
declare(strict_types=1);
require_once __DIR__ . '/head.php';
?>

<!-- =========================================================================
     React 앱 마운트 포인트
     - UI 를 React 로 그리려면 이 div 에 마운트한다(assets/js/src/main.jsx).
     - 초기 데이터를 서버에서 미리 내려주려면 아래처럼 주입 후 React 에서 읽는다.
     ========================================================================= -->
<div id="root"></div>

<script>
  // SSR 없이 필요한 최소 컨텍스트만 주입 (선택)
  window.__APP_CONTEXT__ = {
    apiBase: <?= json_encode(API_BASE) ?>,
    isLoggedIn: <?= is_logged_in() ? 'true' : 'false' ?>,
    user: <?= json_encode(current_user(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  };
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
