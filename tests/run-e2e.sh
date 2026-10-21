#!/usr/bin/env bash
# Run the LAM e2e test suite: both images of this project in front of a real
# OpenLDAP server, on fresh volumes and on the volumes of an existing
# installation.
# Usage: bash tests/run-e2e.sh [pytest-args...]
set -euo pipefail

COMPOSE="tests/e2e/docker-compose.yml"
cd "$(dirname "$0")/.."

cleanup() {
    docker compose -f "$COMPOSE" down -v --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Building test stack..."
docker compose -f "$COMPOSE" build --quiet

echo "==> Starting services..."
docker compose -f "$COMPOSE" up -d --remove-orphans openldap lam lam-proxy unconfigured unconfigured-proxy upgrade upgrade-proxy

echo "==> Writing the volumes of an existing installation..."
docker compose -f "$COMPOSE" run --rm --no-deps upgrade-seed

echo "==> Running tests..."
EXIT=0
docker compose -f "$COMPOSE" run --rm test-runner "$@" || EXIT=$?

echo "==> Health check command of the php-fpm image..."
if docker compose -f "$COMPOSE" exec -T lam /usr/bin/php -r "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);"; then
    echo "  PASS  healthcheck_command"
else
    echo "  FAIL  healthcheck_command"
    EXIT=1
fi

if [[ $EXIT -ne 0 ]]; then
    echo "==> Collecting logs on failure..."
    docker compose -f "$COMPOSE" logs 2>&1 | tail -200
fi

exit $EXIT
