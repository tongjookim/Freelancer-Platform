# 프리랜서 마켓 (Freelancer Platform)

> 의뢰인(Client)과 프리랜서(Freelancer)를 연결하는 **양면 시장(Two-sided Marketplace) 프리랜서 용역 플랫폼**.
> **에스크로(Escrow) 기반 안전 결제**로 대금을 보관하고, 최종 승인 시 수수료를 공제해 정산합니다.

- **백엔드**: PHP 8.4 (프레임워크 없이 경량 구조)
- **프론트엔드**: React 18 + Tailwind CSS (Vite 번들 → `assets/js/script.js`, `assets/css/default.css`)
- **DB**: MySQL (InnoDB, 트랜잭션/행 잠금 기반 에스크로)

---

## 목차
1. [주요 기능](#주요-기능)
2. [기술 스택](#기술-스택)
3. [디렉터리 구조](#디렉터리-구조)
4. [요구사항](#요구사항)
5. [설치 및 실행](#설치-및-실행)
6. [비즈니스 흐름 (에스크로)](#비즈니스-흐름-에스크로)
7. [API 요약](#api-요약)
8. [관리자 페이지](#관리자-페이지)
9. [설정 항목](#설정-항목)
10. [정기 작업 (크론)](#정기-작업-크론)
11. [운영/보안 주의](#운영보안-주의)
12. [라이선스](#라이선스)

---

## 주요 기능

### 사용자 (양면 시장)
- **의뢰인(Client)**: 작업 요청서 작성, 서비스 구매, 결제(에스크로 예치), 결과물 검수·최종 승인, 환불/분쟁 제기
- **프리랜서(Freelancer)**: 서비스·포트폴리오 등록, 프로젝트 지원(입찰), 작업물 파일 발송
- 회원가입/로그인/로그아웃, 비밀번호 변경, 상호 리뷰·평점

### 에스크로(Escrow) 결제
- 의뢰인 결제금액을 플랫폼이 **예치(held)** → 프리랜서 작업물 발송 → 의뢰인 **최종 승인** 시 수수료 공제 후 **정산(released)**
- 모든 자금 이동은 **단일 DB 트랜잭션 + `SELECT ... FOR UPDATE` 행 잠금 + 멱등 키**로 원자성·중복 처리 방지
- **자동 승인**: 작업물 발송 후 `auto_release_days` 경과 시 크론이 자동 정산

### 결제(PG)
- **Stripe**: PaymentIntent + 웹훅(`payment_intent.succeeded`) 서명 검증
- **토스페이먼츠**: 결제 승인 API + 웹훅 서명 검증
- 공통: 웹훅 멱등(`payment_webhooks` UNIQUE), 결제 원장(`payments`), 수동(모의) 결제 폴백

### 분쟁 / 환불
- 분쟁 제기(의뢰인·프리랜서) → **관리자 판정**: 전액 환불 / 부분 환불 / 프리랜서 지급
- 환불 정책: 작업물 발송 **전**에만 의뢰인 자체 환불 허용, 발송 이후에는 분쟁 경유

### 리뷰 모더레이션
- 리뷰 신고(1인 1회), 관리자 블라인드/해제, **신고 임계치 자동 블라인드**, 평점 자동 재집계

### 그 외
- 파일 업로드(작업물·포트폴리오·프로젝트 첨부), 주문 1:1 메시지
- **인앱 알림**(헤더 🔔) + **이메일 아웃박스**(크론 발송)
- 관리자 대시보드/통계(월별 GMV·수수료, 상위 프리랜서, 분쟁 현황)

---

## 기술 스택

| 구분 | 내용 |
| --- | --- |
| 백엔드 | PHP 8.4, PDO(MySQL), 세션 인증 + CSRF, 경량 REST 라우터 |
| 프론트엔드 | React 18, Vite, Tailwind CSS 3, 해시 라우팅 |
| DB | MySQL (InnoDB, utf8mb4) |
| 결제 | Stripe, 토스페이먼츠(Toss Payments) |
| 웹서버 | nginx + PHP-FPM (예시: `work.swnest.net`) |

---

## 디렉터리 구조

```
.
├── index.php                # 메인 페이지 + React 앱 마운트(#root)
├── head.php / footer.php    # 레이아웃 모듈 (CSS/JS 로드, 캐시버스팅)
├── robots.txt
├── terms.php                # 이용약관
├── seller-terms.php         # 판매자 이용약관
├── privacy.php              # 개인정보처리방침
├── .htaccess                # Apache rewrite (/api/* → api/index.php)
│
├── api/
│   └── index.php            # REST 프론트 컨트롤러(라우트 정의)
│
├── includes/
│   ├── config.php           # 설정/세션 부트스트랩 (+ CLI 세션 비활성)
│   ├── db.php               # PDO 싱글턴 + db_transaction()
│   ├── functions.php        # JSON 응답/입력/설정/감사로그
│   ├── auth.php             # 세션 인증/인가 + CSRF
│   ├── api.php              # 경량 REST Router
│   ├── Escrow/EscrowService.php          # 에스크로 예치/정산/환불/분쟁
│   ├── Payments/StripeGateway.php        # Stripe 결제/웹훅
│   ├── Payments/TossPaymentsGateway.php  # 토스 결제/웹훅
│   ├── Upload/Uploader.php               # 파일 업로드 검증/저장
│   ├── Notify/NotificationService.php    # 인앱 알림 + 이메일 적재
│   └── Api/*.php                         # 컨트롤러 (Auth/Market/Order/Admin/Payment/Media/Review/Notification)
│
├── admin/                   # 관리자 페이지 (좌측 사이드바 레이아웃)
│   ├── _layout.php, _bootstrap.php
│   ├── index.php (대시보드), orders.php, order.php (주문 상세)
│   ├── disputes.php, reviews.php, stats.php, finance.php
│   ├── users.php, user.php (회원 상세), settings.php
│   └── login.php, logout.php
│
├── assets/
│   ├── css/src/input.css    # Tailwind 소스
│   ├── css/default.css      # 빌드 산출물 (서버가 직접 서빙)
│   ├── js/src/              # React 소스 (App.jsx, pages/*, api.js, store.jsx, ui.jsx)
│   └── js/script.js         # 빌드 산출물
│
├── db/
│   ├── schema.sql           # 전체 스키마 + 초기 데이터
│   └── migrations/          # 증분 마이그레이션
│
├── scripts/cron/            # 정기 작업 (auto_release.php, send_emails.php, run.sh)
├── uploads/                 # 업로드 저장소 (gitignore, www-data 쓰기)
├── tailwind.config.js, postcss.config.js, vite.config.js, package.json
└── AGENTS.md                # 개발 규칙/운영 문서
```

---

## 요구사항

- PHP **8.1+** (권장 8.4), 확장: `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `json`
- MySQL **5.7+ / 8.0+** (InnoDB)
- Node.js **18+**, npm
- nginx + PHP-FPM (또는 Apache + mod_rewrite)

---

## 설치 및 실행

### 1) 의존성 설치 & 프론트 빌드
```bash
npm install
npm run build        # assets/js/script.js, assets/css/default.css 생성
# 개발 중 자동 빌드: npm run dev
# CSS만: npm run build:css
```

### 2) 데이터베이스 준비
```bash
mysql -u root -p < db/schema.sql      # 신규 설치 (DB freelance_market 자동 생성)
# 기존 DB 업그레이드:
for f in db/migrations/*.sql; do mysql freelance_market < "$f"; done
```

### 3) 애플리케이션 환경변수
`includes/config.php` 는 `$_SERVER`/`getenv` 로 환경변수를 읽습니다. 웹루트 밖(예: `/etc/nginx/web-project-db.env`)에 두고 웹서버가 전달하는 것을 권장합니다.

| 변수 | 설명 |
| --- | --- |
| `APP_ENV` | `production` / `local` (local 시 상세 에러 표시) |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | MySQL 접속 |
| `STRIPE_SECRET_KEY` `STRIPE_WEBHOOK_SECRET` `STRIPE_PUBLISHABLE_KEY` | Stripe (비우면 비활성) |
| `TOSS_SECRET_KEY` `TOSS_WEBHOOK_SECRET` `TOSS_CLIENT_KEY` | 토스페이먼츠 (비우면 비활성) |

> 앱 DB 계정은 root 대신 전용 계정을 사용하세요. 예: `GRANT SELECT,INSERT,UPDATE,DELETE ON freelance_market.* TO 'freelance'@'localhost';`

### 4) 웹서버 (nginx 예시)
- document root: 프로젝트 루트
- `/api/*` → `api/index.php` (프론트 컨트롤러) 로 라우팅
- `\.php$` → PHP-FPM 실행
- `/includes/`, `/db/`, `/node_modules/`, 숨김 파일, `uploads/` 내 PHP 실행 차단
- 업로드: 업로드 폴더를 `www-data` 소유로 두고 쓰기 권한 부여

자세한 nginx 설정·배포 절차는 [`AGENTS.md`](AGENTS.md) 6장을 참고하세요.

### 5) 정기 작업 등록
```bash
# 예시: /etc/cron.d/web-project
*/30 * * * * root /path/to/scripts/cron/run.sh auto_release.php
*/5  * * * * root /path/to/scripts/cron/run.sh send_emails.php
```

---

## 비즈니스 흐름 (에스크로)

```
주문 상태(status)
  pending_payment --pay(예치)--> in_progress --deliver--> delivered --approve(최종승인)--> completed
        |                              |                       |                              (자동승인 크론)
        |cancel                        |dispute              |dispute
        v                              v                       v
     refunded                      disputed --resolve--> completed / refunded / partially_refunded

에스크로 상태(escrow_status)
  none --deposit--> held --release--> released
                  \------refund-----> refunded / partially_refunded
```

- **예치(deposit)**: 결제 성공 → `payments` 기록 + `escrow_transactions(deposit,in)` + `orders` = `in_progress`/`held`
- **정산(release)**: `fee = amount × fee_rate/100`, `net = amount − fee` → `settlements`(pending) + `orders` = `completed`/`released`
- **환불/분쟁(refund/resolve)**: 정책·관리자 판정에 따라 전액/부분 환불 또는 프리랜서 지급
- 정산 지급은 관리자가 `settlements` 를 `paid` 처리

---

## API 요약

모든 응답은 `{ "success": true, "data": ... }` / `{ "success": false, "error": { "message": ... } }` 형식이며, 세션 쿠키(`credentials: include`)와 상태 변경 시 `X-CSRF-Token` 헤더를 사용합니다.

| 영역 | Method & Path |
| --- | --- |
| 인증 | `POST /api/auth/register` `login` `logout` `password` · `GET /api/auth/me` `csrf` |
| 마켓 | `GET /api/categories` · `GET/POST /api/services` · `GET /api/services/{id}` · `GET/POST /api/projects` · `GET /api/projects/{id}` |
| 지원 | `POST /api/projects/{id}/proposals` · `POST /api/proposals/{id}/accept` |
| 주문/에스크로 | `GET /api/orders` · `GET /api/orders/{id}` · `POST /api/orders` · `POST /api/orders/{id}/pay` `deliver` `approve` `refund` `dispute` |
| 결제 | `POST /api/orders/{id}/intent`(Stripe) · `POST /api/orders/{id}/toss/confirm`(토스) · `GET /api/payments/methods` |
| 웹훅 | `POST /api/webhooks/stripe` · `POST /api/webhooks/toss` (CSRF 제외) |
| 파일/포트폴리오 | `POST /api/uploads` · `GET/POST /api/portfolios` · `DELETE /api/portfolios/{id}` |
| 리뷰 | `POST /api/orders/{id}/reviews` · `GET /api/orders/{id}/reviews` · `GET /api/reviews` · `POST /api/reviews/{id}/report` |
| 알림 | `GET /api/notifications` · `POST /api/notifications/{id}/read` · `POST /api/notifications/read-all` |
| 관리자 | `GET /api/admin/dashboard` `stats` `orders` `disputes` `settlements` `review-reports` `users` · `PUT /api/admin/settings` · `POST /api/admin/settlements/{id}/payout` · `POST /api/admin/orders/{id}/resolve-dispute` · `PATCH /api/admin/users/{id}/status` · `POST /api/admin/users/{id}/reset-password` |

---

## 관리자 페이지

좌측 사이드바 기반 레이아웃. `/admin/login.php` 로그인 후 접근합니다.

| 경로 | 설명 |
| --- | --- |
| `/admin/index.php` | 대시보드 (에스크로 보관액·수수료·지급 현황, 최근 주문) |
| `/admin/orders.php`, `/admin/order.php?id=` | 주문·결제 내역 / 주문 상세(원장·결제·작업물·분쟁·채팅, 환불·분쟁 처리) |
| `/admin/disputes.php` | 분쟁 관리(전액/부분 환불·프리랜서 지급) |
| `/admin/reviews.php` | 리뷰 신고/블라인드 관리 |
| `/admin/stats.php` | 통계 (월별 GMV·수수료, 상위 프리랜서, 분쟁 현황) |
| `/admin/finance.php` | 정산 관리(지급 완료 처리) |
| `/admin/users.php`, `/admin/user.php?id=` | 회원 목록 / 회원 상세(상태·임시 비번·거래 이력·리뷰) |
| `/admin/settings.php` | 수수료율·환불정책·자동승인·이메일 알림 설정 |

---

## 설정 항목

`platform_settings` (관리자 → 수수료 설정에서 변경)

| 키 | 기본값 | 설명 |
| --- | --- | --- |
| `default_fee_rate` | `10.00` | 기본 플랫폼 수수료율(%) |
| `min_payout_amount` | `10000` | 최소 정산 지급 금액 |
| `settlement_cycle` | `weekly` | 정산 주기 |
| `client_self_refund_allowed` | `1` | 작업물 발송 전 의뢰인 자체 환불 허용 |
| `dispute_sla_days` | `7` | 분쟁 처리 기한(일) |
| `auto_release_days` | `7` | 검수 대기 자동 승인까지 일수 (0=비활성) |
| `email_notify_enabled` | `0` | 이메일 알림 발송 여부 |
| `review_auto_hide_reports` | `0` | 리뷰 자동 블라인드 신고 임계치 (0=비활성) |

---

## 정기 작업 (크론)

| 스크립트 | 주기 예시 | 역할 |
| --- | --- | --- |
| `scripts/cron/auto_release.php` | 30분 | 검수 대기(`delivered`) + `auto_release_days` 경과 주문 자동 정산 |
| `scripts/cron/send_emails.php` | 5분 | `email_outbox` 의 pending 메일 발송(PHP `mail`) |

`run.sh` 래퍼가 환경변수 파일을 주입한 뒤 PHP 스크립트를 실행합니다.

---

## 운영/보안 주의

- **PHP 코드 변경 후**: opcache 때문에 `systemctl reload php8.4-fpm`(또는 restart) 필요. nginx reload만으로는 반영되지 않습니다.
- **프론트 변경 후**: `npm run build` 로 산출물 재생성.
- **캐시버스팅**: `head.php`/`footer.php`/관리자 레이아웃은 `?v=filemtime` 사용. 정적 자산은 7일 캐시되므로 반드시 버전 쿼리를 유지하세요.
- **자격증명**: DB/PG 키는 웹루트 밖에 보관하고 저장소에 커밋하지 마세요(`.env*`, 업로드·node_modules는 `.gitignore`).
- **업로드**: `uploads/` 내 PHP 실행을 웹서버에서 차단하고, 저장 파일명은 랜덤화합니다.
- **에스크로**: 금전 로직은 항상 트랜잭션·행 잠금·멱등 키로 처리합니다(수정 시 주의).

---

## 라이선스

이 프로젝트는 저장소의 [`LICENSE`](LICENSE) 를 따릅니다.
