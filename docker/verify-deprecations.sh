#!/usr/bin/env bash
# docker/verify-deprecations.sh
#
# Gap-closure measurement script for G-02-1 (02-VERIFICATION.md gaps YAML
# block): Plan 02-01's DOCKER-06 error-log triage reported "PHP Deprecated
# (E_DEPRECATED): 0" while the running container's error_reporting=22527
# (docker/conf.d/zz-app.ini, DOCKER-03 production-parity value) structurally
# excludes E_DEPRECATED (8192) from PHP's error-reporting bitmask. That "0"
# is therefore a guaranteed config artifact, not evidence the codebase is
# deprecation-clean under PHP 8.2.
#
# This script re-runs the same core-flow walkthrough docker/verify-runtime.sh
# Steps A-D already exercise, but with error_reporting temporarily widened to
# E_ALL -- container-local only, never touching the host-tracked, git-committed
# docker/conf.d/zz-app.ini -- then captures the real E_DEPRECATED count (plus
# a side-by-side E_WARNING/E_NOTICE cross-check) from an isolated log slice,
# and unconditionally reverts error_reporting back to 22527 before exiting.
#
# This is measurement-only per CONTEXT.md D-04's stop-don't-fix policy: no
# application code and no committed php.ini config change. The widened
# error_reporting value exists only inside the running container for the
# duration of this script's re-run.
#
# LOCAL-DEV-ONLY TEST CREDENTIALS (throwaway values, same as
# docker/verify-runtime.sh -- see that script's header for full provenance).
# Never reuse these against any real deployment:
#   Ion Auth (public site):  identity=00000                  password=Phase2LocalOnly!25
#   FUEL CMS admin:           user_name=phase2_verify_admin  password=Phase2FuelLocalOnly!25
#
# Reusable in future phases as a targeted E_DEPRECATED re-measurement tool.

export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL="*"

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

BASE_URL="http://localhost"
ION_JAR="verify_deprecations_ionauth_cookies.txt"
FUEL_JAR="verify_deprecations_fuel_cookies.txt"
OUT_HTML="verify_deprecations_tmp.html"
LOG_DUMP="verify_deprecations_log.txt"

IONAUTH_IDENTITY="00000"
IONAUTH_PASSWORD="Phase2LocalOnly!25"
FUEL_USER="phase2_verify_admin"
FUEL_PASSWORD="Phase2FuelLocalOnly!25"

CONTAINER_INI_PATH="/usr/local/etc/php/conf.d/zz-deprecation-check.ini"
EXPECTED_ERROR_REPORTING="22527"

FAILED=0
REVERT_DONE=0

check() {
  # check "<label>" <0-if-pass-else-nonzero>
  local label="$1"
  local ok="$2"
  if [ "$ok" -eq 0 ]; then
    echo "  [PASS] $label"
  else
    echo "  [FAIL] $label"
    FAILED=1
  fi
}

fatal_present() {
  # fatal_present <file> -> exit 0 (true) if a fatal-error string IS present
  grep -qE 'Fatal error|Uncaught TypeError|Uncaught Error|Uncaught Exception' "$1"
}

extract_csrf_field() {
  # extract_csrf_field <html-file> -> prints "NAME VALUE" for the first hidden
  # input whose name attribute ends in _FUEL (FUEL's own manual session-CSRF
  # convention, Fuel_base_controller::_get_csrf_token_name()).
  local file="$1"
  local tag name value
  tag="$(grep -oE '<input[^>]*name="[A-Za-z0-9_]*_FUEL"[^>]*>' "$file" | head -1)"
  name="$(echo "$tag" | grep -oE 'name="[A-Za-z0-9_]*_FUEL"' | head -1 | sed -E 's/name="([^"]*)"/\1/')"
  value="$(echo "$tag" | grep -oE 'value="[^"]*"' | head -1 | sed -E 's/value="([^"]*)"/\1/')"
  echo "$name $value"
}

revert_error_reporting() {
  # Unconditional revert: removes the container-local-only ini override and
  # restarts apache so mod_php re-reads conf.d without it, then confirms via a
  # live `php -i` check (not assumed) that error_reporting is back to 22527.
  # This function is wired into `trap cleanup EXIT` below AND called once at
  # the end of the normal control-flow path, so it only actually performs the
  # revert once (guarded by $REVERT_DONE).
  if [ "$REVERT_DONE" -eq 1 ]; then
    return
  fi
  REVERT_DONE=1

  echo ""
  echo "=== Revert: removing container-local error_reporting override, restoring 22527 ==="
  docker compose exec -T apache rm -f "$CONTAINER_INI_PATH" 2>/dev/null || true
  docker compose restart apache >/dev/null 2>&1 || true

  # Give apache a moment to finish restarting before querying php -i.
  local attempt actual
  actual=""
  for attempt in 1 2 3 4 5; do
    actual="$(docker compose exec -T apache php -i 2>/dev/null | grep -i '^error_reporting' | head -1 | grep -oE '[0-9]+' | head -1)"
    if [ -n "$actual" ]; then
      break
    fi
    sleep 2
  done

  if [ "$actual" = "$EXPECTED_ERROR_REPORTING" ]; then
    echo "  [PASS] Confirmed error_reporting restored to $EXPECTED_ERROR_REPORTING on the running container (live php -i check)"
  else
    echo "  [FAIL] error_reporting did NOT confirm as $EXPECTED_ERROR_REPORTING after revert (observed: '${actual:-<empty>}')"
    FAILED=1
  fi
}

cleanup() {
  # Guaranteed revert per the plan's Task 1 action item 8/9: this trap runs
  # even if an earlier step failed or the script was interrupted, so the
  # running container can never be left on the widened error_reporting mask.
  revert_error_reporting
  rm -f "$ION_JAR" "$FUEL_JAR" "$OUT_HTML" "$LOG_DUMP"
}
trap cleanup EXIT

echo "=== docker/verify-deprecations.sh: gap-closure G-02-1 DOCKER-06 re-measurement ==="
echo "Ensuring local Docker stack is up..."
docker compose up -d

echo ""
echo "=== Step 0: (re-)seed local-only test accounts (idempotent) ==="

# vrlv3_tunnukset id=1 (identity=00000) -- always safe to re-run.
IONAUTH_HASH="$(docker compose exec -T apache php -r "echo password_hash('$IONAUTH_PASSWORD', PASSWORD_DEFAULT);" 2>/dev/null | tr -d '\r\n')"
if [ -z "$IONAUTH_HASH" ]; then
  check "generate bcrypt hash for Ion Auth test password" 1
else
  check "generate bcrypt hash for Ion Auth test password" 0
  docker compose exec -T db mariadb -uroot vrlv3 -e "UPDATE vrlv3_tunnukset SET password='${IONAUTH_HASH}' WHERE id=1;" 2>/dev/null
  check "UPDATE vrlv3_tunnukset SET password=... WHERE id=1 (idempotent reset)" $?
fi

# fuel_users id=1 (user_name=phase2_verify_admin) -- fresh salt + sha1(password.salt)
# each run; id=1 is the PRIMARY KEY conflict target (fuel_users has no unique
# index on user_name, only PRIMARY KEY(id) -- confirmed in fuel_schema.sql),
# matching Plan 02-01's original fixed-id insert so this is safely re-runnable.
FUEL_SALT="$(docker compose exec -T apache php -r "echo bin2hex(random_bytes(16));" 2>/dev/null | tr -d '\r\n')"
if [ -z "$FUEL_SALT" ]; then
  check "generate fresh 32-hex salt for FUEL admin test password" 1
else
  check "generate fresh 32-hex salt for FUEL admin test password" 0
  FUEL_HASH="$(docker compose exec -T apache php -r "echo sha1('$FUEL_PASSWORD' . '$FUEL_SALT');" 2>/dev/null | tr -d '\r\n')"
  docker compose exec -T db mariadb -uroot vrlv3 -e "INSERT INTO fuel_users (id, user_name, password, email, first_name, last_name, language, reset_key, salt, super_admin, active) VALUES (1, '${FUEL_USER}', '${FUEL_HASH}', 'phase2_verify_admin@example.invalid', 'Phase2', 'Verify', 'english', '', '${FUEL_SALT}', 'yes', 'yes') ON DUPLICATE KEY UPDATE password=VALUES(password), salt=VALUES(salt), active='yes', super_admin='yes';" 2>/dev/null
  check "INSERT ... ON DUPLICATE KEY UPDATE fuel_users id=1 (idempotent reseed)" $?
fi

echo ""
echo "=== Step 1: capture UTC timestamp marker BEFORE widening error_reporting (log isolation) ==="
SINCE_TS="$(date -u +%Y-%m-%dT%H:%M:%S)"
echo "  marker: $SINCE_TS"

echo ""
echo "=== Step 2: write container-local-only error_reporting=E_ALL override, restart apache ==="
echo "  (writes ONLY to the running container's writable layer at $CONTAINER_INI_PATH --"
echo "   never to the host-tracked, git-committed docker/conf.d/zz-app.ini)"
docker compose exec -T apache sh -c "printf '; TEMPORARY gap-closure (G-02-1) measurement override.\n; Written and removed by docker/verify-deprecations.sh -- container-local only,\n; never persisted to the host-tracked docker/conf.d/zz-app.ini.\nerror_reporting = E_ALL\n' > $CONTAINER_INI_PATH"
check "write temporary container-local ini override ($CONTAINER_INI_PATH)" $?

docker compose restart apache >/dev/null 2>&1
check "docker compose restart apache (apply widened mask -- mod_php only re-reads conf.d at startup)" $?

echo ""
echo "  Confirming widened error_reporting is live..."
WIDENED_ACTUAL=""
for attempt in 1 2 3 4 5; do
  WIDENED_ACTUAL="$(docker compose exec -T apache php -i 2>/dev/null | grep -i '^error_reporting' | head -1 | grep -oE '[0-9]+' | head -1)"
  if [ -n "$WIDENED_ACTUAL" ]; then
    break
  fi
  sleep 2
done
echo "  observed error_reporting after widen: ${WIDENED_ACTUAL:-<empty>}"

echo ""
echo "=== Step 3: re-run core-flow walkthrough (Steps A-D equivalent) with fresh cookie jars ==="

# A1: public homepage
HTTP_A1="$(curl -s -o "$OUT_HTML" -w '%{http_code}' "$BASE_URL/")"
if [ "$HTTP_A1" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "GET / returns HTTP 200, no fatal error in body" 0
else
  echo "    (got HTTP $HTTP_A1)"
  check "GET / returns HTTP 200, no fatal error in body" 1
fi

# FUEL admin login (fresh jar, established after the restart)
curl -s -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" "$BASE_URL/fuel/login"
read -r CSRF_NAME CSRF_VALUE <<< "$(extract_csrf_field "$OUT_HTML")"
if [ -z "$CSRF_NAME" ] || [ -z "$CSRF_VALUE" ]; then
  check "GET /fuel/login exposes a *_FUEL CSRF hidden field" 1
else
  check "GET /fuel/login exposes a *_FUEL CSRF hidden field ($CSRF_NAME)" 0
fi

HTTP_A3="$(curl -s -L -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" -w '%{http_code}' \
  --data-urlencode "user_name=$FUEL_USER" \
  --data-urlencode "password=$FUEL_PASSWORD" \
  --data-urlencode "forward=" \
  --data-urlencode "${CSRF_NAME}=${CSRF_VALUE}" \
  "$BASE_URL/fuel/login")"
if [ "$HTTP_A3" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "POST /fuel/login (FUEL admin) succeeds, no fatal error in body" 0
else
  echo "    (got HTTP $HTTP_A3)"
  check "POST /fuel/login (FUEL admin) succeeds, no fatal error in body" 1
fi

HTTP_A4="$(curl -s -L -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" -w '%{http_code}' "$BASE_URL/fuel/blocks")"
if [ "$HTTP_A4" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "GET /fuel/blocks (post-login) returns HTTP 200, no fatal error in body" 0
else
  echo "    (got HTTP $HTTP_A4)"
  check "GET /fuel/blocks (post-login) returns HTTP 200, no fatal error in body" 1
fi

# Public Ion Auth login (fresh jar)
HTTP_B1="$(curl -s -c "$ION_JAR" -b "$ION_JAR" -o "$OUT_HTML" -w '%{http_code}' \
  --data-urlencode "identity=$IONAUTH_IDENTITY" \
  --data-urlencode "password=$IONAUTH_PASSWORD" \
  --data-urlencode "url=/" \
  "$BASE_URL/auth/login")"
if [ "$HTTP_B1" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "POST /auth/login (public login) completes, no fatal error in body" 0
else
  echo "    (got HTTP $HTTP_B1)"
  check "POST /auth/login (public login) completes, no fatal error in body" 1
fi

HTTP_B2="$(curl -s -c "$ION_JAR" -b "$ION_JAR" -o "$OUT_HTML" -w '%{http_code}' "$BASE_URL/profiili")"
if [ "$HTTP_B2" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "GET /profiili (same cookie jar) returns HTTP 200" 0
else
  echo "    (got HTTP $HTTP_B2)"
  check "GET /profiili (same cookie jar) returns HTTP 200" 1
fi

# One FUEL admin CRUD create -- distinct block name from Plan 02-01's
# phase2_verify_block, since fuel_blocks has a UNIQUE KEY on (name, language).
curl -s -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" "$BASE_URL/fuel/blocks/create"
read -r CSRF_NAME2 CSRF_VALUE2 <<< "$(extract_csrf_field "$OUT_HTML")"
if [ -z "$CSRF_NAME2" ] || [ -z "$CSRF_VALUE2" ]; then
  check "GET /fuel/blocks/create exposes a *_FUEL CSRF hidden field" 1
else
  check "GET /fuel/blocks/create exposes a *_FUEL CSRF hidden field ($CSRF_NAME2)" 0
fi

HTTP_C1="$(curl -s -L -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" -w '%{http_code}' \
  --data-urlencode "id=" \
  --data-urlencode "name=phase2_deprecation_check_block" \
  --data-urlencode "description=Phase 2 gap-closure G-02-1 deprecation re-measurement block" \
  --data-urlencode "view=<p>Phase 2 gap-closure G-02-1 deprecation re-measurement block</p>" \
  --data-urlencode "language=english" \
  --data-urlencode "published=yes" \
  --data-urlencode "${CSRF_NAME2}=${CSRF_VALUE2}" \
  "$BASE_URL/fuel/blocks/create")"
if [ "$HTTP_C1" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "POST /fuel/blocks/create (phase2_deprecation_check_block) completes, no fatal error in body" 0
else
  echo "    (got HTTP $HTTP_C1)"
  check "POST /fuel/blocks/create (phase2_deprecation_check_block) completes, no fatal error in body" 1
fi

# update_stats scoring calc, reusing the Ion Auth jar
HTTP_D1="$(curl -s -c "$ION_JAR" -b "$ION_JAR" -o "$OUT_HTML" -w '%{http_code}' "$BASE_URL/update_stats/update_stats")"
if [ "$HTTP_D1" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "GET /update_stats/update_stats returns HTTP 200, no fatal error in body" 0
else
  echo "    (got HTTP $HTTP_D1)"
  if fatal_present "$OUT_HTML"; then
    echo "    >>> BLOCKER (stop, not fix -- CONTEXT.md D-04): genuine fatal found in fragile scoring code."
    echo "    >>> Matched line(s):"
    grep -nE 'Fatal error|Uncaught TypeError|Uncaught Error|Uncaught Exception' "$OUT_HTML" | sed 's/^/    >>>   /'
    echo "    >>> Trigger URL: GET $BASE_URL/update_stats/update_stats"
    echo "    >>> Per CONTEXT.md D-04: Update_stats.php/Kisajarjestelma.php/Jaos.php/Porrastetut.php/Fuel_modules.php were NOT modified by this script."
  fi
  check "GET /update_stats/update_stats returns HTTP 200, no fatal error in body" 1
fi

echo ""
echo "=== Step 4: capture isolated log slice (since $SINCE_TS) and count by type ==="
docker compose logs --no-color --since "$SINCE_TS" apache > "$LOG_DUMP" 2>&1

DEP_COUNT="$(grep -c 'PHP Deprecated' "$LOG_DUMP" || true)"
WARN_COUNT="$(grep -c 'PHP Warning' "$LOG_DUMP" || true)"
NOTICE_COUNT="$(grep -c 'PHP Notice' "$LOG_DUMP" || true)"

echo ""
echo "  --- Counts by type (isolated to THIS run's log slice, error_reporting=E_ALL) ---"
echo "  *** CORRECTED DATA (previously a config artifact under error_reporting=22527) ***"
echo "  E_DEPRECATED (\"PHP Deprecated\"): $DEP_COUNT"
echo "  --- Cross-check data (already reliable under the old 22527 mask per the verifier's finding) ---"
echo "  E_WARNING (\"PHP Warning\"):       $WARN_COUNT"
echo "  E_NOTICE (\"PHP Notice\"):         $NOTICE_COUNT"

echo ""
echo "  --- Top offending files (PHP Deprecated lines only, by 'in <file> on line N') ---"
TOP_DEP_FILES="$(grep 'PHP Deprecated' "$LOG_DUMP" \
  | grep -oE ' in /var/www/html/[^ ]+ on line [0-9]+' \
  | sed -E 's/^ in //; s/ on line [0-9]+$//' \
  | sort | uniq -c | sort -rn | head -10 || true)"
if [ -z "$TOP_DEP_FILES" ]; then
  echo "  (no PHP Deprecated lines found in this isolated log slice)"
else
  echo "$TOP_DEP_FILES" | sed 's/^/  /'
fi

echo ""
echo "  --- Top offending files (PHP Warning/Notice lines, cross-check) ---"
TOP_WN_FILES="$(grep -E 'PHP (Warning|Notice)' "$LOG_DUMP" \
  | grep -oE ' in /var/www/html/[^ ]+ on line [0-9]+' \
  | sed -E 's/^ in //; s/ on line [0-9]+$//' \
  | sort | uniq -c | sort -rn | head -10 || true)"
if [ -z "$TOP_WN_FILES" ]; then
  echo "  (no PHP Warning/Notice lines found in this isolated log slice)"
else
  echo "$TOP_WN_FILES" | sed 's/^/  /'
fi

# Explicit revert now (also guaranteed by the EXIT trap regardless of outcome).
revert_error_reporting

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "=== PASS: widen -> re-run -> isolated-log-capture -> revert sequence completed; no new fatal found ==="
else
  echo "=== FAIL: see [FAIL] lines above (script exit code reflects sequence/revert integrity and new-fatal checks only, never the deprecation COUNT itself per CONTEXT.md D-05) ==="
fi

exit "$FAILED"
