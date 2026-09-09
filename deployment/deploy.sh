#!/usr/bin/env bash
# Application release on the VPS. No HostAfrica, no DNS, no migrate --force.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
require_root

cd "${APP_ROOT}"

if [[ ! -f "${APP_ROOT}/.env" ]]; then
  echo "Missing ${APP_ROOT}/.env — copy deployment/env/.env.production.template first" >&2
  exit 1
fi

if ! grep -qE '^APP_KEY=base64:.+' "${APP_ROOT}/.env"; then
  echo "APP_KEY is empty. Phase A: sudo -u ${DEPLOY_USER} -H php ${APP_ROOT}/artisan key:generate" >&2
  exit 1
fi

if [[ ! -d "${APP_ROOT}/.git" ]]; then
  echo "Missing ${APP_ROOT}/.git — run server-setup.sh first" >&2
  exit 1
fi

if [[ -f "${ROLLBACK_PIN}" ]]; then
  echo "ROLLBACK_PIN exists at ${ROLLBACK_PIN}" >&2
  echo "This blocks git pull so a rollback cannot be silently overwritten. Remove the pin when you intend to deploy origin again." >&2
  exit 1
fi

PREVIOUS="$(run_as_app git rev-parse HEAD)"
mkdir -p "$(dirname "${RELEASE_LOG}")"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) before ${PREVIOUS}" >>"${RELEASE_LOG}"

# Single-branch clones only track the original setup branch.
run_as_app git fetch origin "+refs/heads/${REPO_BRANCH}:refs/remotes/origin/${REPO_BRANCH}"
if run_as_app git show-ref --verify --quiet "refs/heads/${REPO_BRANCH}"; then
  run_as_app git checkout "${REPO_BRANCH}"
else
  run_as_app git checkout -b "${REPO_BRANCH}" --track "origin/${REPO_BRANCH}"
fi
run_as_app git pull --ff-only origin "${REPO_BRANCH}"

install_public_front_controller "${SCRIPT_DIR}"
install_nginx_site "${SCRIPT_DIR}"
laravel_build

CURRENT="$(run_as_app git rev-parse HEAD)"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) after ${CURRENT} (was ${PREVIOUS})" >>"${RELEASE_LOG}"

reload_web

echo "deploy.sh complete at ${CURRENT}"
