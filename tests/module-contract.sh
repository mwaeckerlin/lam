#!/usr/bin/env bash
# Module contract: php-fpm and the PHP CLI load every module of
# tests/expected-modules.txt.
#
# Usage: tests/module-contract.sh IMAGE

set -uo pipefail
cd "$(dirname "$0")/.."

IMAGE="${1:?usage: tests/module-contract.sh IMAGE}"
PASS=0
FAIL=0
declare -a FAILED_NAMES

_pass() { PASS=$((PASS + 1)); echo "  PASS  $1"; }
_fail() { FAIL=$((FAIL + 1)); FAILED_NAMES+=("$1"); echo "  FAIL  $1: $2"; }

_modules() {
    docker run --rm --pull=never --entrypoint "$1" "${IMAGE}" -m 2>&1 \
        | grep -v -E '^\[|^$' | tr '[:upper:]' '[:lower:]' | sort -u
}

echo "==> Module contract: ${IMAGE}"
echo "        PHP in the image: $(docker run --rm --pull=never "${IMAGE}" -v 2>&1 | head -n 1)"

for binary in /usr/sbin/php-fpm /usr/bin/php; do
    loaded=$(_modules "${binary}")
    missing=$(grep -v -E '^#|^$' tests/expected-modules.txt | tr '[:upper:]' '[:lower:]' | sort -u \
        | comm -23 - <(printf '%s\n' "${loaded}"))
    name="modules_of_$(basename "${binary}")"
    echo "        loaded by ${binary}: $(echo ${loaded})"
    if [[ -z "${missing}" ]]; then
        _pass "${name}"
    else
        _fail "${name}" "missing: $(echo ${missing}); loaded: $(echo ${loaded})"
    fi
done

echo ""
echo "==> Module contract results: ${PASS} passed, ${FAIL} failed"
if [[ ${FAIL} -gt 0 ]]; then
    echo "==> Failed contracts: ${FAILED_NAMES[*]}"
    exit 1
fi
