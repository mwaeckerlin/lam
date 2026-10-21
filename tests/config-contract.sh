#!/usr/bin/env bash
# Config contract: the image ships no password and presets no configuration
# variable (a preset value would count as set by the operator and overwrite
# the configuration in the volumes on every start), prepares LAM before every
# request, and keeps the volumes of the old image.
#
# Usage: tests/config-contract.sh IMAGE

set -uo pipefail

IMAGE="${1:?usage: tests/config-contract.sh IMAGE}"

PASS=0
FAIL=0
declare -a FAILED_NAMES

_pass() { PASS=$((PASS + 1)); echo "  PASS  $1"; }
_fail() { FAIL=$((FAIL + 1)); FAILED_NAMES+=("$1"); echo "  FAIL  $1: $2"; }

echo "==> Config contract: ${IMAGE}"

if ! docker image inspect "${IMAGE}" > /dev/null 2>&1; then
    _fail "${IMAGE}_image_exists" "image not built — run 'npm run build' first"
else
    ENVIRONMENT=$(docker image inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "${IMAGE}")
    for name in LAM_PASSWORD LAM_CONFIGURATION_PASSWORD LAM_LANG LDAP_SERVER LDAP_DOMAIN LDAP_BASE_DN LDAP_USERS_DN LDAP_GROUPS_DN LDAP_USER \
            LAM_CONFIGURATION_DATABASE LAM_CONFIGURATION_HOST LAM_CONFIGURATION_PORT LAM_CONFIGURATION_DATABASE_NAME LAM_CONFIGURATION_USER; do
        if grep -q "^${name}=" <<< "${ENVIRONMENT}"; then
            _fail "no_default_${name,,}" "the image sets ${name}"
        else
            _pass "no_default_${name,,}"
        fi
    done

    VOLUMES=$(docker image inspect --format '{{range $k, $v := .Config.Volumes}}{{println $k}}{{end}}' "${IMAGE}")
    for volume in /etc/ldap-account-manager /var/lib/ldap-account-manager; do
        if grep -qx "${volume}" <<< "${VOLUMES}"; then
            _pass "volume_${volume//\//_}"
        else
            _fail "volume_${volume//\//_}" "not declared, declared are: $(echo ${VOLUMES})"
        fi
    done

    INFO=$(docker run --rm --pull=never "${IMAGE}" -i 2>&1)
    if grep -Eq 'auto_prepend_file.*/usr/local/lib/lam/preconfigure\.php' <<< "${INFO}"; then
        _pass "preconfigure_prepended"
    else
        _fail "preconfigure_prepended" "$(grep 'auto_prepend_file' <<< "${INFO}" || echo 'setting not reported')"
    fi
    if docker run --rm --pull=never --entrypoint /usr/bin/php "${IMAGE}" -l /usr/local/lib/lam/preconfigure.php > /dev/null 2>&1; then
        _pass "preconfigure_syntax"
    else
        _fail "preconfigure_syntax" "$(docker run --rm --pull=never --entrypoint /usr/bin/php "${IMAGE}" -l /usr/local/lib/lam/preconfigure.php 2>&1)"
    fi

    HEALTH=$(docker image inspect --format '{{json .Config.Healthcheck.Test}}' "${IMAGE}")
    if grep -q '/usr/bin/php' <<< "${HEALTH}"; then
        _pass "healthcheck_declared"
    else
        _fail "healthcheck_declared" "healthcheck is ${HEALTH}"
    fi
fi

echo ""
echo "==> Config contract results: ${PASS} passed, ${FAIL} failed"
if [[ ${FAIL} -gt 0 ]]; then
    echo "==> Failed contracts: ${FAILED_NAMES[*]}"
    exit 1
fi
