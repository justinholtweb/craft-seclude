#!/usr/bin/env bash
#
# Renders every Seclude control-panel screen as a logged-in admin and fails on any that does not
# return 200. Twig errors in a CP template are invisible to the PHP integration checks, and a
# permissions plugin whose Policies screen white-screens is worse than one that is simply wrong.
#
#   bash tests/manual/cp-smoke.sh
#
set -uo pipefail

CONTAINER=${CONTAINER:-ddev-plugin-testing-web}
HOST=${HOST:-plugin-testing.ddev.site}
USERNAME=${SECLUDE_CP_USER:-admin}
PASSWORD=${SECLUDE_CP_PASS:-claudepassword}

pass=0
fail=0

run() {
    docker exec -i "$CONTAINER" bash -c "$1"
}

echo "Logging in…"

# Fetch to a file rather than piping into grep: under `pipefail`, grep exits on its first match,
# curl dies of SIGPIPE, and the pipeline reports a failure that never happened.
LOGIN=$(run "
    rm -f /tmp/seclude-cookies /tmp/seclude-login
    # Ask Craft for a token rather than scraping one out of the login page's inline JS, which
    # changes shape between releases.
    CSRF=\$(curl -s -c /tmp/seclude-cookies -H 'Host: $HOST' -H 'Accept: application/json' \
        http://127.0.0.1/admin/actions/users/session-info \
        | sed -n 's/.*\"csrfTokenValue\":\"\([^\"]*\)\".*/\\1/p')
    curl -s -o /tmp/seclude-login -w '%{http_code}' -b /tmp/seclude-cookies -c /tmp/seclude-cookies \
        -H 'Host: $HOST' -H 'Accept: application/json' -H 'X-Requested-With: XMLHttpRequest' \
        -X POST http://127.0.0.1/admin/actions/users/login \
        --data-urlencode \"loginName=$USERNAME\" \
        --data-urlencode \"password=$PASSWORD\" \
        --data-urlencode \"CRAFT_CSRF_TOKEN=\$CSRF\"
")

if [ "$LOGIN" != "200" ]; then
    echo "  login failed (HTTP $LOGIN)"
    run "cat /tmp/seclude-login" | head -c 300
    echo
    exit 1
fi

# Confirm from the session itself rather than from the login response body, whose shape has
# changed across Craft releases.
if run "curl -s -b /tmp/seclude-cookies -H 'Host: $HOST' -H 'Accept: application/json' \
        http://127.0.0.1/admin/actions/users/session-info | grep -q '\"isGuest\":false'"; then
    echo "  logged in as $USERNAME"
else
    echo "  login rejected:"
    run "cat /tmp/seclude-login" | head -c 300
    echo
    exit 1
fi

check() {
    local path="$1"
    local label="$2"
    local code

    # `-L`: Craft redirects a bare element index to its first source, and a 302 there is correct
    # behaviour rather than a failure.
    code=$(run "curl -sL -o /tmp/seclude-page -w '%{http_code}' -b /tmp/seclude-cookies -H 'Host: $HOST' 'http://127.0.0.1$path'")

    if [ "$code" = "200" ]; then
        printf '  \033[32m✓\033[0m %-42s %s\n' "$label" "$path"
        pass=$((pass + 1))
    else
        printf '  \033[31m✗\033[0m %-42s %s (HTTP %s)\n' "$label" "$path" "$code"
        run "grep -o '<h1[^>]*>[^<]*</h1>\|Exception[^<]\{0,120\}' /tmp/seclude-page | head -3"
        fail=$((fail + 1))
    fi
}

# Seed a demo so the screens that need real data — an existing policy with a condition builder,
# an assignment editor, an explain report — are actually exercised rather than skipped.
echo "Seeding demo…"
SEED=$(run "cd /var/www/html && php /var/www/craft-seclude/tests/manual/seed-demo.php" | tail -1)
POLICY_ID=$(echo "$SEED" | sed -n 's/.*policyId=\([0-9]*\).*/\1/p')
BEN_ID=$(echo "$SEED" | sed -n 's/.*benId=\([0-9]*\).*/\1/p')
ENTRY_ID=$(echo "$SEED" | sed -n 's/.*entryId=\([0-9]*\).*/\1/p')

if [ -z "$POLICY_ID" ] || [ -z "$BEN_ID" ] || [ -z "$ENTRY_ID" ]; then
    echo "  seed failed: $SEED"
    exit 1
fi

echo "  policy $POLICY_ID, user $BEN_ID, entry $ENTRY_ID"

cleanup() {
    run "cd /var/www/html && php /var/www/craft-seclude/tests/manual/seed-demo.php --clean" >/dev/null 2>&1
}
trap cleanup EXIT

echo
echo "Screens"
check /admin/seclude                      "Seclude root"
check /admin/seclude/policies             "Policies index"
check /admin/seclude/policies/new         "New policy"
check /admin/seclude/assignments          "Assignments index"
check /admin/seclude/explain              "Who can edit what"
check /admin/seclude/refusals             "Refusals"
check /admin/settings/plugins/seclude     "Settings"

echo
echo "Screens that need real data"
check "/admin/seclude/policies/$POLICY_ID"                    "Existing policy (condition builder)"
check "/admin/seclude/assignments/$POLICY_ID/$BEN_ID"         "Assignment editor"
check "/admin/seclude/explain?userId=$BEN_ID&elementId=$ENTRY_ID" "Explain report"

echo
echo "Saving a policy through the real form endpoint"

probe() {
    run "cd /var/www/html && php /var/www/craft-seclude/tests/manual/probe.php $1"
}

GROUP_UID=$(probe group-uid)
SECTION_UID=$(probe section-uid)

SAVE_CODE=$(run "
    CSRF=\$(curl -s -b /tmp/seclude-cookies -c /tmp/seclude-cookies -H 'Host: $HOST' -H 'Accept: application/json' \
        http://127.0.0.1/admin/actions/users/session-info \
        | sed -n 's/.*csrfTokenValue\":\"\\([^\"]*\\)\".*/\\1/p')
    curl -s -o /tmp/seclude-save -w '%{http_code}' -b /tmp/seclude-cookies -c /tmp/seclude-cookies \
        -H 'Host: $HOST' -X POST http://127.0.0.1/admin/actions/seclude/policies/save \
        --data-urlencode CRAFT_CSRF_TOKEN=\$CSRF \
        --data-urlencode 'name=Smoke posted policy' \
        --data-urlencode 'handle=secludeSmokePosted' \
        --data-urlencode 'enabled=1' \
        --data-urlencode 'subjects[userGroupUids][]=$GROUP_UID' \
        --data-urlencode 'scope[type]=entries' \
        --data-urlencode 'scope[sourceUids][entries][]=$SECTION_UID' \
        --data-urlencode 'grants[assigned][enabled]=1' \
        --data-urlencode 'abilities[view]=1' \
        --data-urlencode 'abilities[save]=1' \
        --data-urlencode 'abilities[propose]=1'
")

assert_eq() {
    local label="$1" expected="$2" actual="$3"

    if [ "$actual" = "$expected" ]; then
        printf '  \033[32m\xe2\x9c\x93\033[0m %-42s %s\n' "$label" "$actual"
        pass=$((pass + 1))
    else
        printf '  \033[31m\xe2\x9c\x97\033[0m %-42s expected %s, got %s\n' "$label" "$expected" "$actual"
        fail=$((fail + 1))
    fi
}

assert_eq "form accepted" "302" "$SAVE_CODE"

# One assertion covering the whole parse: grants, scope sources, subjects and abilities all
# survived the round trip through the form in the shape the model expects.
assert_eq "posted policy round-trips intact" "1,assigned,1,1,view+save+propose" "$(probe describe-posted)"

probe delete-posted >/dev/null

echo
echo "Element indexes still render with the plugin installed"
# Craft 5 serves the entries index at /admin/content/entries; /admin/entries is a redirect to it.
check /admin/content/entries              "Entries index"
check /admin/categories                   "Categories index"
check /admin/assets                       "Assets index"
check /admin/users                        "Users index"

echo
if [ "$fail" -eq 0 ]; then
    printf '\033[32m%d passed, 0 failed\033[0m\n' "$pass"
else
    printf '\033[31m%d passed, %d failed\033[0m\n' "$pass" "$fail"
fi

exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
