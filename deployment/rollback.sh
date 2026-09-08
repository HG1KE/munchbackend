#!/usr/bin/env bash
# Code-only rollback to a previous git commit recorded by deploy.sh.
# Usage: rollback.sh [commit]
# Default: the last "before" hash in storage/app/releases.log
# Does not touch MySQL, HostAfrica, DNS, .env, oauth keys, or uploaded media.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
require_root

cd "${APP_ROOT}"

if [[ -n "${1:-}" ]]; then
  TARGET="$1"
elif [[ -f "${RELEASE_LOG}" ]]; then
  TARGET="$(awk '/ before / {h=$3} END {print h}' "${RELEASE_LOG}")"
else
  echo "No commit given and no ${RELEASE_LOG}" >&2
  exit 1
fi

if [[ -z "${TARGET}" ]]; then
  echo "Could not determine rollback commit" >&2
  exit 1
fi

run_as_app git fetch origin
if ! run_as_app git cat-file -e "${TARGET}^{commit}"; then
  echo "Not a valid git commit: ${TARGET}" >&2
  exit 1
fi

CURRENT="$(run_as_app git rev-parse HEAD)"
if [[ "${CURRENT}" == "$(run_as_app git rev-parse "${TARGET}")" ]]; then
  echo "Already at ${TARGET}" >&2
  exit 1
fi

echo "Rolling back code ${CURRENT} -> ${TARGET}"
echo "WARNING: database, .env, and storage/app/public are not reverted."

# Stay on the deploy branch (do not detach HEAD). Pin blocks the next pull.
run_as_app git checkout "${REPO_BRANCH}"
run_as_app git reset --hard "${TARGET}"

install_public_front_controller "${SCRIPT_DIR}"
laravel_build

printf '%s\n' "${TARGET}" >"${ROLLBACK_PIN}"
chown "${DEPLOY_USER}:${WEB_USER}" "${ROLLBACK_PIN}"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) rollback ${TARGET}" >>"${RELEASE_LOG}"

reload_web

echo "rollback.sh complete at $(run_as_app git rev-parse HEAD)"
echo "Remove ${ROLLBACK_PIN} before the next deploy.sh (which would otherwise ff-only back to origin)."
