# 프로젝트 개요: 프리랜서 용역 플랫폼 (양면 시장 / Two-sided Marketplace)

크몽, 숨고와 유사한 **양면 시장(Two-sided Marketplace) 형태의 프리랜서 용역 플랫폼**.
의뢰인(Client)과 프리랜서(Freelancer)를 연결하고, 에스크로 기반의 안전 결제를 제공한다.

---

## 1. 기술 스택 및 아키텍처

| 구분 | 내용 |
| --- | --- |
| 프론트엔드 | React, Tailwind CSS (PHP 내에서 렌더링되거나 API 통신을 통해 분리 구동) |
| 백엔드 | PHP |
| 핵심 과제 | React 기반 모던 UI와 기존 PHP 파일 구조가 어떻게 데이터를 주고받을지(REST API 형태 등) 명확한 연동 구조 제시 |

### 디렉토리 / 파일 구조

```
/assets/css/default.css   # Tailwind 빌드 파일 및 글로벌 스타일
/assets/js/script.js      # 공통 유틸리티 및 번들링된 React 엔트리
/includes/*.php           # DB 연결, 공통 함수, API 라우팅 처리
/admin/*.php              # 관리자 페이지 (수수료 관리, 결제 내역 조회, 회원 관리 등)
/head.php                 # 레이아웃 모듈 (헤더)
/footer.php               # 레이아웃 모듈 (푸터)
/index.php                # 메인 페이지 및 React 앱 마운트 포인트
/robots.txt
```

---

## 2. 핵심 비즈니스 로직 및 작동 방식

### 2.1 사용자 권한 및 프로필 관리 (양면 시장)
- **의뢰인(Client)**: 작업 요청서 작성, 결제, 결과물 승인
- **프리랜서(Freelancer)**: 포트폴리오/프로필 등록, 작업 지원, 작업물 발송

### 2.2 에스크로(Escrow) 기반 안전 결제 시스템
- 의뢰인이 작업을 의뢰할 때 결제한 대금을 플랫폼이 예치(Escrow)
- 프리랜서가 작업을 완료하고 의뢰인이 **'최종 승인'**을 누르면, 예치된 금액에서
  플랫폼 수수료를 공제한 뒤 프리랜서에게 지급
- **트랜잭션 안전성 필수**

### 2.3 관리자(Admin) 수익 및 정산 관리
- 플랫폼 중간 수수료율(%) 설정 기능
- 전체 에스크로 보관 금액 및 프리랜서 지급/정산 내역 관리 대시보드

---

## 3. 우선 산출물 (Deliverables)

요구사항 충족을 위해 아래 세 가지를 먼저 제시한다.

1. **데이터베이스(MySQL) 스키마** — 사용자, 서비스, 결제(에스크로) 상태 관리
2. **API 구조** — PHP 백엔드 ↔ React 프론트엔드 간 데이터 통신
3. **에스크로 핵심 PHP 백엔드 로직** — 대금 예치 및 수수료 정산 처리

## 4. 작업 순서

1. 데이터베이스(DB) 스키마 설계
2. React-PHP 연동 구조 설계
3. 에스크로 결제 로직 구현

---

## 5. 개발 규칙 (Agent 작업 가이드)

- 파일 경로가 불확실하면 **먼저 `glob` 또는 `bash`(ls·find)로 실제 경로를 확인**한 뒤 `read`/`edit`를 호출한다.
- 현재 작업 디렉터리(`/home/web-project/works`)를 기준으로 삼는다. 확실하지 않으면 `pwd`를 먼저 확인한다.
- 추측으로 코드를 고치지 말고, **고치기 전에 대상 파일을 읽어 확인**한다.
- 답변 및 주석은 한국어를 사용한다.
- 기존 PHP 파일 구조를 존중하고, 명명 규칙·코드 스타일을 주변 코드와 일치시킨다.
- 금전(결제/정산) 관련 로직은 **DB 트랜잭션**으로 원자성을 보장하고, 상태 전이를 명확히 검증한다.

---

## 6. 운영 정보 (배포/실행)

### 빌드 (React + Tailwind)
- `npm install` → `npm run build` (JSX+CSS 동시 번들)
- 산출물: `assets/js/script.js`, `assets/css/default.css` (커밋 대상, 서버가 직접 서빙)
- 감시 빌드: `npm run dev` (vite build --watch)

### 데이터베이스 (MySQL)
- DB: `freelance_market` / 스키마: `db/schema.sql`
- 애플리케이션 전용 계정: `freelance@localhost` (앱은 root 를 쓰지 않는다)
- 자격증명은 웹루트 밖에 보관: `/etc/nginx/web-project-db.env` (root 전용, 600)
- nginx 가 `fastcgi_param` 으로 전달 → `includes/config.php` 의 `env()` 가 `$_SERVER`/getenv 순회 조회
- 스키마 재적용: `mysql freelance_market < db/schema.sql`

### 웹 서버 (nginx)
- vhost: `/etc/nginx/sites-available/work.swnest.net` (sites-enabled 심볼릭 링크)
- 도메인: `https://work.swnest.net` / document root: `/home/web-project/works`
- `*.swnest.net` 와일드카드를 이기기 위해 정확한 server_name + `listen 218.38.12.116` 사용
- TLS: `/etc/letsencrypt/live/swnest.net/` 와일드카드 인증서 공용
- PHP-FPM: `unix:/run/php/php8.4-fpm.sock`
- 라우팅: `/api/*` → `api/index.php` / `\.php$` → PHP 실행 / `/includes`, `/db`, `/node_modules` 차단
- 로그: `/var/log/nginx/web-project/`

### 관리자
- 로그인: `https://work.swnest.net/admin/login.php`
- 초기 계정: `admin@work.swnest.net` (최초 비밀번호는 즉시 변경할 것)

### Stripe 결제 (PG)
- 환경변수(`/etc/nginx/web-project-db.env`, 비우면 비활성 → 수동 결제 폴백):
  - `STRIPE_SECRET_KEY` (sk_...), `STRIPE_WEBHOOK_SECRET` (whsec_...), `STRIPE_PUBLISHABLE_KEY` (pk_...)
- 결제 흐름: 프론트 `POST /api/orders/{id}/intent` → PaymentIntent 생성 → Stripe.js 결제 →
  `POST /api/webhooks/stripe`(서명검증, CSRF 제외) 수신 시 `EscrowService::deposit` 으로 예치
- 웹훅 멱등: `payment_webhooks(provider,event_id)` UNIQUE, `payments(pg_provider,pg_tx_id)` UNIQUE
- Stripe 대시보드 웹훅 URL: `https://work.swnest.net/api/webhooks/stripe` (이벤트: `payment_intent.succeeded`)

### 토스페이먼츠 결제 (PG)
- 환경변수(`/etc/nginx/web-project-db.env`, 비우면 비활성):
  - `TOSS_SECRET_KEY` (test_sk_/live_sk_), `TOSS_WEBHOOK_SECRET`, `TOSS_CLIENT_KEY` (프론트 노출)
- 승인: 프론트가 Toss SDK로 결제 → `POST /api/orders/{id}/toss/confirm { payment_key, amount }`
  → 서버가 `POST /v1/payments/confirm`(Basic auth)로 승인 → `EscrowService::deposit`(pg_provider='toss')
- 웹훅: `POST /api/webhooks/toss` (CSRF 제외). 서명 `Tosspayments-Webhook-Signature = base64(HMAC-SHA256(rawBody, secret))`
  이벤트 `PAYMENT_STATUS_CHANGED`+`DONE` → 멱등 예치 (`payment_webhooks(provider,event_id=paymentKey)`)
- 결제수단 조회: `GET /api/payments/methods` → `{ stripe:{enabled,publishable_key}, toss:{enabled,client_key} }`
- 프론트: 주문 상세에서 Stripe/토스 버튼 노출, 토스는 성공 리다이렉트(`#/orders/{id}/toss-success`) 후 승인 처리
- 웹훅 URL: `https://work.swnest.net/api/webhooks/toss`

### DB 마이그레이션
- 신규 설치: `mysql freelance_market < db/schema.sql`
- 기존 DB: `db/migrations/*.sql` 순서대로 적용
  - `20261110_disputes_stripe.sql` : 분쟁/부분환불 + Stripe 웹훅 테이블
  - `20261110_auto_release.sql` : 자동 승인 설정(`auto_release_days`)
  - `20261111_notifications.sql` : 인앱 알림 테이블
  - `20261111_reviews_moderation.sql` : 리뷰 신고/블라인드 + 이메일 아웃박스
  - `20261111_review_autohide.sql` : 리뷰 자동 블라인드 임계치 설정

### 파일 업로드
- 저장 경로: `/home/web-project/works/uploads/` (www-data 소유 775), 웹 경로 `/uploads/...`
- nginx `location ^~ /uploads/` 로 서빙하되 **업로드 폴더 내 PHP 실행 차단**, `X-Content-Type-Options: nosniff`
- 업로드 한도: nginx `client_max_body_size 20m` + PHP `upload_max_filesize=10M / post_max_size=12M`
- 검증: 용도별 화이트리스트(확장자+MIME, `Uploader`), 저장명 랜덤(원본명 비노출)
- 용도(purpose): `deliverable` | `portfolio` | `project` | `message`

### 정기 작업 (cron)
- `/etc/cron.d/web-project` : 30분마다 `scripts/cron/run.sh auto_release.php`
- 검수 대기(delivered) 후 `auto_release_days`(기본 7) 경과 시 자동 정산. 0이면 비활성
- 래퍼 `scripts/cron/run.sh` 가 `/etc/nginx/web-project-db.env` 를 환경으로 주입 후 실행
- 로그: `/var/log/nginx/web-project/cron.log`

### 배포 주의 (중요)
- PHP 코드 변경 후 opcache 때문에 이전 스크립트가 실행될 수 있다.
  **`systemctl reload php8.4-fpm`** (또는 restart) 로 반영한다.
  `systemctl reload nginx` 만으로는 PHP 코드 변경이 반영되지 않는다.
- 프론트 수정 후 `npm run build` 로 `assets/js/script.js`, `assets/css/default.css` 재생성.

## 7. 에스크로 분쟁 / 환불 정책

- 상태: `orders.status` 에 `disputed`, `partially_refunded` / `escrow_status` 에 `partially_refunded` 추가.
- 분쟁 제기(의뢰인/프리랜서): `in_progress`·`delivered`·`revision_requested` + 에스크로 `held` 에서만 가능.
- 관리자 처리(`EscrowService::resolveDispute`):
  - `refund_full` : 전액 환불 → `refunded`/`refunded`
  - `refund_partial` : 입력 환불액은 의뢰인에게, 잔여금은 수수료 공제 후 프리랜서 지급 → `partially_refunded`
  - `release_to_freelancer` : 전액 정산 → `completed`/`released`
- 자체 환불 정책: **작업물 발송 전** 의뢰인만 직접 환불 가능(`client_self_refund_allowed`).
  작업물 제출 이후에는 분쟁을 통해서만 환불 → `EscrowService::refund()` 에서 강제.
- 관련 API:
  - `POST /api/orders/{id}/dispute` (제기)
  - `GET /api/admin/disputes` · `POST /api/admin/orders/{id}/resolve-dispute` (처리)
- 관리자 화면: `/admin/disputes.php`

## 8. 파일 업로드 / 리뷰 / 자동화 / 통계

### 파일 업로드 (`includes/Upload/Uploader.php`, `includes/Api/MediaController.php`)
- `POST /api/uploads` (multipart: `file`, `purpose`) → `{ path, url, name, size, mime }`
- 작업물 발송 시 `file_path` 로 첨부, 포트폴리오 썸네일/프로젝트 첨부에 재사용

### 포트폴리오
- `GET /api/portfolios?freelancer_id=` · `POST /api/portfolios`(프리랜서) · `DELETE /api/portfolios/{id}`

### 리뷰/평점 (`includes/Api/ReviewController.php`)
- `POST /api/orders/{id}/reviews` { rating(1~5), comment } — 완료(completed) 주문 당사자만, 1인 1회
- `GET /api/orders/{id}/reviews` · `GET /api/reviews?user_id=`
- 작성 시 `freelancer_profiles.rating_avg` / `rating_count` 자동 집계

### 자동 승인 (에스크로 자동 정산)
- `scripts/cron/auto_release.php` — `orders.status='delivered'` + `delivered_at` 경과분을 `EscrowService::release()` 로 정산(멱등)

### 관리자 통계
- `GET /api/admin/stats` · 화면 `/admin/stats.php`
- 월별 GMV/수수료, 상위 프리랜서(지급액), 분쟁 현황

### 관리자 회원 상세
- `/admin/user.php?id=` — 기본정보/상태변경/임시비번, 프리랜서 프로필·평점,
  의뢰/수행 주문 이력, 정산 내역, 받은 리뷰 (회원 목록에서 이름 클릭)

### 인앱 알림 (`includes/Notify/NotificationService.php`, `includes/Api/NotificationController.php`)
- `notifications` 테이블(user_id, type, title, body, link, ref_type, ref_id, is_read)
- 발생 이벤트: `order.created`(프리랜서)·`order.paid`(프리랜서)·`order.delivered`(의뢰인)·
  `order.settled`(프리랜서)·`dispute.opened`(상대방+관리자)·`dispute.resolved`(양측)·
  `review.created`(대상)·`settlement.paid`(프리랜서)·`proposal.submitted`(의뢰인)·`proposal.accepted`(프리랜서)
- API: `GET /api/notifications` · `POST /api/notifications/{id}/read` · `POST /api/notifications/read-all`
- 프론트: 헤더 🔔 벨(미읽음 배지, 30초 폴링, 드롭다운)
- 마이그레이션: `20261111_notifications.sql`

### 프로젝트 첨부
- 의뢰인 요청서 작성 시 파일 첨부(`purpose=project`) → 프로젝트 상세에 다운로드 링크

### 리뷰 신고/블라인드
- 리뷰 `status`(visible/hidden) + `review_reports`(신고자 1인 1건)
- `POST /api/reviews/{id}/report` { reason } — 작성자 본인 제외 로그인 사용자
- 관리자: `GET /api/admin/review-reports?status=` · `POST /api/admin/reviews/{id}/hide` · `.../restore`
- 블라인드/해제 시 `rating_avg`·`rating_count` **visible 기준 재집계**, 공개 조회는 hidden 제외
- 화면: `/admin/reviews.php` · 프론트 리뷰 목록에 "신고" 버튼
- 자동 블라인드: 설정 `review_auto_hide_reports`(신고 임계치, 0=사용안함) 도달 시 자동 hidden + 관리자 알림

### 관리자 주문 상세 액션
- `/admin/order.php?id=` 에서 escrow held 주문에 대해 **강제 환불**, 진행 중 분쟁에 대해 **전액/부분 환불·프리랜서 지급** 실행
- 기존 API 재사용: `POST /api/orders/{id}/refund`, `POST /api/admin/orders/{id}/resolve-dispute`

### 관리자 주문 상세
- `/admin/order.php?id=` — 당사자, 에스크로 원장, 결제/정산, 작업물, 분쟁, 채팅 (주문목록/회원상세에서 진입)

### 이메일 알림 (아웃박스 + 크론)
- 설정 `email_notify_enabled=1` 이면 인앱 알림 생성 시 `email_outbox` 에 적재
- `scripts/cron/send_emails.php` 가 5분마다 pending 발송(PHP mail), 3회 실패 시 failed
- `/etc/cron.d/web-project` 에 5분 주기 등록, 로그 `/var/log/nginx/web-project/cron.log`
- 실서비스에서는 SMTP(예: sendmail/msmtp) 구성 필요

### 프론트 화면 (React, `assets/js/src/`)
- 마켓(서비스/프로젝트 목록·상세), 주문(결제·Stripe·발송·승인·환불·분쟁·채팅·리뷰),
  워크스페이스(서비스/요청서 등록, 포트폴리오 관리), 프로필(비밀번호 변경)
