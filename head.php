<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';

/** 페이지별 제목/설명을 $page_title, $page_desc 로 넘길 수 있다. */
$page_title = $page_title ?? '프리랜서 마켓 — 안전한 에스크로 거래';
$page_desc  = $page_desc  ?? '의뢰인과 프리랜서를 연결하는 양면 시장. 에스크로로 안전하게 거래하세요.';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($page_desc, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="canonical" href="<?= htmlspecialchars((($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES, 'UTF-8') ?>">

  <!-- Pretendard 웹폰트 -->
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
  <!-- Tailwind 빌드 결과 + 글로벌 스타일 -->
  <link rel="stylesheet" href="/assets/css/default.css?v=<?= filemtime(__DIR__ . '/assets/css/default.css') ?>">

  <!-- 프론트가 별도 도메인에 있으면 API base 를 주입 -->
  <meta name="api-base" content="<?= API_BASE ?>">
</head>
<body class="bg-slate-50 text-slate-900">
