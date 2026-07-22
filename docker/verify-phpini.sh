#!/usr/bin/env bash
# docker/verify-phpini.sh
#
# Verifies DOCKER-01/02/03 for Phase 1 (docker-php-8-2-image-alignment):
#   1. docker compose build apache succeeds (DOCKER-01/02 build gate)
#   2. All 7 required extensions are present in `php -m`, and each writes its
#      own distinct docker-php-ext-*.ini file with no filename collisions (DOCKER-02)
#   3. Every DOCKER-03 php.ini directive matches phpinfo.md's captured production
#      values, verified via an actual HTTP request against the running container
#      (NOT via `docker exec ... php -i`/CLI — the CLI SAPI hardcodes
#      max_execution_time/max_input_time to 0/-1 regardless of ini content;
#      see 01-RESEARCH.md Pitfall 1).
#
# Reusable in Phase 2 as a regression check.

set -e

# Prevent Git-Bash/MSYS from mangling absolute Unix paths passed as arguments
# to `docker compose run` (e.g. /usr/local/etc/php/conf.d/ would otherwise be
# rewritten to a Windows path like C:/Program Files/Git/usr/local/...).
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL="*"

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

PHPINFO_FILE="phpinfo_verify.php"
FAILED=0

cleanup() {
  if [ -f "$PHPINFO_FILE" ]; then
    rm -f "$PHPINFO_FILE"
    echo "Cleaned up temporary $PHPINFO_FILE"
  fi
}
trap cleanup EXIT

check() {
  # check "<label>" "<grep pattern>" "<haystack file>"
  local label="$1"
  local pattern="$2"
  local haystack="$3"
  if grep -qE "$pattern" "$haystack"; then
    echo "  [PASS] $label"
  else
    echo "  [FAIL] $label (pattern: $pattern)"
    FAILED=1
  fi
}

echo "=== Step 1: docker compose build apache (DOCKER-01/02 build gate) ==="
docker compose build apache

echo ""
echo "=== Step 2: extension presence + conf.d filename collision check (DOCKER-02) ==="
MODULES_OUTPUT="$(docker compose run --rm apache php -m)"
for ext in mysqli bz2 intl bcmath opcache calendar pdo_mysql; do
  # opcache is listed as "Zend OPcache" in `php -m`'s [Zend Modules] section,
  # not as a bare "opcache" line in [PHP Modules] — check both forms.
  if [ "$ext" = "opcache" ]; then
    pattern='^(opcache|Zend OPcache)$'
  else
    pattern="^${ext}$"
  fi
  if echo "$MODULES_OUTPUT" | grep -qiE "$pattern"; then
    echo "  [PASS] extension present: $ext"
  else
    echo "  [FAIL] extension missing: $ext"
    FAILED=1
  fi
done

CONFD_LISTING="$(docker compose run --rm apache ls /usr/local/etc/php/conf.d/)"
echo "$CONFD_LISTING"
EXT_INI_COUNT="$(echo "$CONFD_LISTING" | grep -cE '^docker-php-ext-(mysqli|bz2|intl|bcmath|opcache|calendar|pdo-mysql|pdo_mysql)\.ini$' || true)"
DISTINCT_EXT_INI_COUNT="$(echo "$CONFD_LISTING" | grep -E '^docker-php-ext-.*\.ini$' | sort -u | wc -l | tr -d ' ')"
if [ "$DISTINCT_EXT_INI_COUNT" -ge 7 ]; then
  echo "  [PASS] $DISTINCT_EXT_INI_COUNT distinct docker-php-ext-*.ini files present, no collisions"
else
  echo "  [FAIL] expected at least 7 distinct docker-php-ext-*.ini files, found $DISTINCT_EXT_INI_COUNT"
  FAILED=1
fi

echo ""
echo "=== Step 3: start container, fetch phpinfo() via HTTP (DOCKER-03) ==="
docker compose up -d apache

echo "<?php phpinfo();" > "$PHPINFO_FILE"

# Give Apache a moment to notice the new bind-mounted file / be ready.
READY=0
for i in $(seq 1 15); do
  if curl -s -o /dev/null -w "%{http_code}" http://localhost/phpinfo_verify.php | grep -q "200"; then
    READY=1
    break
  fi
  sleep 1
done

if [ "$READY" -ne 1 ]; then
  echo "  [FAIL] http://localhost/phpinfo_verify.php did not return HTTP 200 in time"
  FAILED=1
fi

HTTP_BODY="$(curl -s http://localhost/phpinfo_verify.php)"
HTML_FILE="$(mktemp)"
echo "$HTTP_BODY" > "$HTML_FILE"

echo ""
echo "--- DOCKER-03 directive checks (via HTTP, per Pitfall 1) ---"
check "memory_limit=128M"             'memory_limit.*128M' "$HTML_FILE"
check "upload_max_filesize=2M"        'upload_max_filesize.*2M' "$HTML_FILE"
check "post_max_size=8M"              'post_max_size.*8M' "$HTML_FILE"
check "max_execution_time=30"         'max_execution_time.*30' "$HTML_FILE"
check "max_input_time=60"             'max_input_time.*60' "$HTML_FILE"
check "max_input_vars=1000"           'max_input_vars.*1000' "$HTML_FILE"
check "max_file_uploads=20"           'max_file_uploads.*20' "$HTML_FILE"
check "date.timezone=Europe/Helsinki" 'date.timezone.*Europe/Helsinki' "$HTML_FILE"
check "error_reporting=22527"         'error_reporting.*22527' "$HTML_FILE"

echo ""
echo "--- opcache.* directive checks ---"
check "opcache.enable=On"                        'opcache.enable.*(On|1)' "$HTML_FILE"
check "opcache.memory_consumption=128"            'opcache.memory_consumption.*128' "$HTML_FILE"
check "opcache.interned_strings_buffer=8"         'opcache.interned_strings_buffer.*8' "$HTML_FILE"
check "opcache.max_accelerated_files=10000"       'opcache.max_accelerated_files.*10000' "$HTML_FILE"
check "opcache.revalidate_freq=2"                 'opcache.revalidate_freq.*2' "$HTML_FILE"
check "opcache.validate_timestamps=On"            'opcache.validate_timestamps.*(On|1)' "$HTML_FILE"
check "opcache.save_comments=On"                  'opcache.save_comments.*(On|1)' "$HTML_FILE"

echo ""
echo "--- session.* directive checks ---"
check "session.save_handler=files"      'session.save_handler.*files' "$HTML_FILE"
check "session.gc_maxlifetime=1440"     'session.gc_maxlifetime.*1440' "$HTML_FILE"
check "session.gc_probability=1"        'session.gc_probability.*1' "$HTML_FILE"
check "session.gc_divisor=1000"         'session.gc_divisor.*1000' "$HTML_FILE"
check "session.cookie_httponly=Off"     'session.cookie_httponly.*(Off|0)' "$HTML_FILE"
check "session.cookie_secure=Off"       'session.cookie_secure.*(Off|0)' "$HTML_FILE"
check "session.use_strict_mode=Off"     'session.use_strict_mode.*(Off|0)' "$HTML_FILE"
check "session.name=PHPSESSID"          'session.name.*PHPSESSID' "$HTML_FILE"

echo ""
echo "--- D-01/D-02/D-03 exception block checks ---"
check "display_errors=On"           'display_errors.*(On|1)' "$HTML_FILE"
check "display_startup_errors=On"   'display_startup_errors.*(On|1)' "$HTML_FILE"
check "log_errors=On"               'log_errors.*(On|1)' "$HTML_FILE"

rm -f "$HTML_FILE"

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "=== PASS: all DOCKER-01/02/03 checks passed ==="
else
  echo "=== FAIL: one or more DOCKER-01/02/03 checks failed (see [FAIL] lines above) ==="
fi

exit "$FAILED"
