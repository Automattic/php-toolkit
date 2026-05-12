#!/usr/bin/env bash
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

cd "$ROOT_DIR"
docker compose run --rm sandbox-push-md-e2e bash bin/test-push-md-git-actions.sh
