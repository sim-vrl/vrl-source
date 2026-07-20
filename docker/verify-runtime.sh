#!/usr/bin/env bash
# docker/verify-runtime.sh
#
# Verifies DOCKER-04/05/06 for Phase 2 (php-8-2-runtime-verification):
#   Step A (DOCKER-04): boot gate -- homepage GET, FUEL admin login, then GET
#     /fuel/blocks -- exercises Fuel_modules.php:194-224's add() is_subclass_of()
#     path end-to-end during real FUEL bootstrap, not just static code inspection.
#   Step B (DOCKER-05): public login core flow -- POST /auth/login then GET
#     /profiili with the same cookie jar.
#   Step C (DOCKER-05): one real FUEL CMS admin CRUD action -- create a
#     fuel_blocks row via the admin panel.
#   Step D (DOCKER-05): one competition-scoring calculation -- GET
#     /update_stats/update_stats (CONTEXT.md D-03 candidate, most recent hotfix
#     churn). Stop-not-fix policy (CONTEXT.md D-04): if a genuine fatal is
#     found here, FAILED is set but Update_stats.php/Kisajarjestelma.php/
#     Jaos.php/Porrastetut.php/Fuel_modules.php are never touched by this
#     script.
#   Step E (DOCKER-05): AWS SES email send -- explicitly SKIPPED this phase
#     (CONTEXT.md D-01/D-02, no credentials available). No code path invoked.
#   Step F (DOCKER-06): error-log triage -- counts by PHP Deprecated/Warning/
#     Notice plus a top-offending-files breakdown. Never sets FAILED
#     (CONTEXT.md D-05): only Steps A and D's fatal-error checks may fail this
#     script.
#
# LOCAL-DEV-ONLY TEST CREDENTIALS (throwaway values, seeded by 02-01-PLAN.md
# Task 1 as a direct DB mutation against the running local `db` container --
# never written to database/*.sql). Never reuse these against any real
# deployment:
#   Ion Auth (public site):  identity=00000                  password=Phase2LocalOnly!25
#   FUEL CMS admin:           user_name=phase2_verify_admin  password=Phase2FuelLocalOnly!25
#
# Deviations from 02-01-PLAN.md's literal text (documented in full in
# 02-01-SUMMARY.md):
#   - `docker compose exec db mysql ...` -> `docker compose exec db mariadb ...`
#     -- the `mysql` binary does not exist in the mariadb:11.4.2 image used by
#     docker-compose.yml; only `mariadb` is present.
#   - Step C's mutating POST goes to `fuel/blocks/create`, not
#     `fuel/blocks/form` -- Module::form() (fuel/modules/fuel/controllers/
#     Module.php:1605) is GET-only view rendering and never processes $_POST;
#     POSTing there does not persist anything and in fact throws a PHP Warning
#     (missing view file) confirming the route is effectively dead code. The
#     real create-action route is Module::create() (Module.php:784), which is
#     what actually calls _process_create(). A GET of fuel/blocks/form is
#     still referenced below as a secondary confirmation touchpoint so this
#     comment and the automated `fuel/blocks/form` check both reflect the same
#     real route inventory.
#   - Step F reads `docker compose logs apache` instead of `tail -n 200
#     /var/log/apache2/error.log` inside the container -- this Apache image's
#     /var/log/apache2/error.log is a symlink to /dev/stderr (and access.log
#     to /dev/stdout), the standard "log to container stdout/stderr" pattern.
#     `docker compose exec apache tail ... /var/log/apache2/error.log` hangs
#     indefinitely because a fresh exec session's /dev/stderr is not the same
#     stream as the main foreground apache2 process's stderr that Docker's log
#     driver captures. `docker compose logs apache` reads the log driver's
#     captured stream directly and returns immediately.
#   - The FUEL admin CSRF hidden-field name is dynamically scraped (name
#     attribute ending in `_FUEL`) rather than hardcoded to
#     `csrf_test_name_FUEL` -- empirically the live field name is
#     `ci_csrf_token_FUEL`, not derived from config.php's `csrf_token_name`
#     value as the plan's action text assumed. Dynamic scraping (as the plan's
#     <action> prose itself directs) is what's implemented; the exact literal
#     field name in the prose was inaccurate.
#
# Reusable in future phases as a regression check.

export MSYS_NO_PATHCONV=1

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

BASE_URL="http://localhost"
ION_JAR="verify_runtime_ionauth_cookies.txt"
FUEL_JAR="verify_runtime_fuel_cookies.txt"
OUT_HTML="verify_runtime_tmp.html"
LOG_DUMP="verify_runtime_apache_log.txt"

IONAUTH_IDENTITY="00000"
IONAUTH_PASSWORD="Phase2LocalOnly!25"
FUEL_USER="phase2_verify_admin"
FUEL_PASSWORD="Phase2FuelLocalOnly!25"

FAILED=0

cleanup() {
  rm -f "$ION_JAR" "$FUEL_JAR" "$OUT_HTML" "$LOG_DUMP"
}
trap cleanup EXIT

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

echo "=== docker/verify-runtime.sh: DOCKER-04/05/06 runtime verification ==="
echo "Ensuring local Docker stack is up..."
docker compose up -d

echo ""
echo "=== Step A: DOCKER-04 boot gate (homepage + FUEL admin login + fuel/blocks list) ==="

# A1: public homepage
HTTP_A1="$(curl -s -o "$OUT_HTML" -w '%{http_code}' "$BASE_URL/")"
if [ "$HTTP_A1" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "GET / returns HTTP 200, no fatal error in body" 0
else
  echo "    (got HTTP $HTTP_A1)"
  check "GET / returns HTTP 200, no fatal error in body" 1
fi

# A2: GET /fuel/login to establish a FUEL session + scrape CSRF token
curl -s -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" "$BASE_URL/fuel/login"
read -r CSRF_NAME CSRF_VALUE <<< "$(extract_csrf_field "$OUT_HTML")"
if [ -z "$CSRF_NAME" ] || [ -z "$CSRF_VALUE" ]; then
  check "GET /fuel/login exposes a *_FUEL CSRF hidden field" 1
else
  check "GET /fuel/login exposes a *_FUEL CSRF hidden field ($CSRF_NAME)" 0
fi

# A3: POST FUEL admin login
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

# A4: GET /fuel/blocks -- exercises Fuel_modules::add()'s is_subclass_of() path
HTTP_A4="$(curl -s -L -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" -w '%{http_code}' "$BASE_URL/fuel/blocks")"
if [ "$HTTP_A4" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "GET /fuel/blocks (post-login) returns HTTP 200, no fatal error in body (Fuel_modules.php:194-224 add() live path)" 0
else
  echo "    (got HTTP $HTTP_A4)"
  check "GET /fuel/blocks (post-login) returns HTTP 200, no fatal error in body (Fuel_modules.php:194-224 add() live path)" 1
fi

echo ""
echo "=== Step B: DOCKER-05 public login core flow ==="

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
  check "GET /profiili (same cookie jar) returns HTTP 200 -- not the unauthenticated redirect to '/'" 0
else
  echo "    (got HTTP $HTTP_B2)"
  check "GET /profiili (same cookie jar) returns HTTP 200 -- not the unauthenticated redirect to '/'" 1
fi

echo ""
echo "=== Step C: DOCKER-05 one FUEL CMS admin CRUD action (create a fuel_blocks row) ==="

# Secondary confirmation touchpoint: fuel/blocks/form is a real, routable
# Module::form() action (GET-only view render) -- confirm it doesn't fatal,
# even though (per the header comment above) it is NOT the working save
# route and is never POSTed to.
HTTP_C0="$(curl -s -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" -w '%{http_code}' "$BASE_URL/fuel/blocks/form")"
if [ "$HTTP_C0" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "GET /fuel/blocks/form returns HTTP 200, no fatal error in body (non-mutating touchpoint)" 0
else
  echo "    (got HTTP $HTTP_C0)"
  check "GET /fuel/blocks/form returns HTTP 200, no fatal error in body (non-mutating touchpoint)" 1
fi

# Real create-form GET to scrape a fresh CSRF value + confirm the create form renders
curl -s -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" "$BASE_URL/fuel/blocks/create"
read -r CSRF_NAME2 CSRF_VALUE2 <<< "$(extract_csrf_field "$OUT_HTML")"
if [ -z "$CSRF_NAME2" ] || [ -z "$CSRF_VALUE2" ]; then
  check "GET /fuel/blocks/create exposes a *_FUEL CSRF hidden field" 1
else
  check "GET /fuel/blocks/create exposes a *_FUEL CSRF hidden field ($CSRF_NAME2)" 0
fi

# POST the actual create action (Module::create(), Module.php:784)
HTTP_C1="$(curl -s -L -c "$FUEL_JAR" -b "$FUEL_JAR" -o "$OUT_HTML" -w '%{http_code}' \
  --data-urlencode "id=" \
  --data-urlencode "name=phase2_verify_block" \
  --data-urlencode "description=Phase 2 runtime verification test block" \
  --data-urlencode "view=<p>Phase 2 runtime verification test block</p>" \
  --data-urlencode "language=english" \
  --data-urlencode "published=yes" \
  --data-urlencode "${CSRF_NAME2}=${CSRF_VALUE2}" \
  "$BASE_URL/fuel/blocks/create")"
if [ "$HTTP_C1" = "200" ] && ! fatal_present "$OUT_HTML"; then
  check "POST /fuel/blocks/create completes, no fatal error in body" 0
else
  echo "    (got HTTP $HTTP_C1)"
  check "POST /fuel/blocks/create completes, no fatal error in body" 1
fi

BLOCK_ROW_COUNT="$(docker compose exec -T db mariadb -uroot vrlv3 -N -e "SELECT COUNT(*) FROM fuel_blocks WHERE name='phase2_verify_block';" 2>/dev/null | tr -d '\r')"
if [ "$BLOCK_ROW_COUNT" = "1" ]; then
  check "exactly one fuel_blocks row named 'phase2_verify_block' exists after the CRUD action" 0
else
  echo "    (found $BLOCK_ROW_COUNT matching row(s))"
  check "exactly one fuel_blocks row named 'phase2_verify_block' exists after the CRUD action" 1
fi

echo ""
echo "=== Step D: DOCKER-05 one competition-scoring calculation (update_stats, reusing Ion Auth session) ==="

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
echo "=== Step E: DOCKER-05 AWS SES email send (explicit skip) ==="
echo "  [SKIPPED] AWS SES email-send was NOT exercised this phase -- no AWS SES credentials"
echo "            are available locally (CONTEXT.md D-01/D-02). This is a stated gap, not a"
echo "            silent omission. No Vrl_email.php code path was invoked."

echo ""
echo "=== Step F: DOCKER-06 error-log triage (never affects FAILED/exit code) ==="
echo "  Confirming PHP error_log directive + actual log destination..."
docker compose exec -T apache php -i 2>/dev/null | grep -i '^error_log' || true
echo "  (empty/'no value' above is expected -- /var/log/apache2/error.log is a symlink to"
echo "   /dev/stderr in this image; PHP falls back to Apache's own captured log stream,"
echo "   read here via 'docker compose logs apache' rather than an in-container tail.)"

docker compose logs --no-color apache > "$LOG_DUMP" 2>&1

DEP_COUNT="$(grep -c 'PHP Deprecated' "$LOG_DUMP" || true)"
WARN_COUNT="$(grep -c 'PHP Warning' "$LOG_DUMP" || true)"
NOTICE_COUNT="$(grep -c 'PHP Notice' "$LOG_DUMP" || true)"

echo ""
echo "  --- Counts by type ---"
echo "  E_DEPRECATED (\"PHP Deprecated\"): $DEP_COUNT"
echo "  E_WARNING (\"PHP Warning\"):       $WARN_COUNT"
echo "  E_NOTICE (\"PHP Notice\"):         $NOTICE_COUNT"

echo ""
echo "  --- Top offending files (PHP Deprecated/Warning/Notice lines, by 'in <file> on line N') ---"
TOP_FILES="$(grep -E 'PHP (Deprecated|Warning|Notice)' "$LOG_DUMP" \
  | grep -oE ' in /var/www/html/[^ ]+ on line [0-9]+' \
  | sed -E 's/^ in //; s/ on line [0-9]+$//' \
  | sort | uniq -c | sort -rn | head -10 || true)"
if [ -z "$TOP_FILES" ]; then
  echo "  (no PHP Deprecated/Warning/Notice lines found in captured Apache/PHP log output this run)"
else
  echo "$TOP_FILES" | sed 's/^/  /'
fi
echo "  NOTE (CONTEXT.md D-05): the above volume never fails this script -- only the"
echo "  fatal-error/core-flow checks in Steps A and D can set FAILED."

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "=== PASS: all DOCKER-04/05 fatal-error/core-flow checks passed (SES explicitly skipped, DOCKER-06 triage captured above) ==="
else
  echo "=== FAIL: one or more DOCKER-04/05 fatal-error/core-flow checks failed (see [FAIL] lines above) ==="
fi

exit "$FAILED"
