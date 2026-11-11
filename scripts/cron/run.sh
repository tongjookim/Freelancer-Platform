#!/usr/bin/env bash
# 크론 래퍼: DB 자격증명을 환경으로 주입한 뒤 PHP 크론 스크립트를 실행한다.
#   사용: run.sh auto_release.php | run.sh send_emails.php
set -euo pipefail

ENV_FILE="${WEB_PROJECT_ENV:-/etc/nginx/web-project-db.env}"
if [[ -r "$ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    . "$ENV_FILE"
    set +a
fi

exec php "$(dirname "$0")/$1"
