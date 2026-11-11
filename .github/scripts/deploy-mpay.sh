#!/usr/bin/env bash
# Stage the MPAY PHP source archive via the existing 1Panel API.
# Deliberately does not extract, migrate, overwrite .env, or restart any service.
set -euo pipefail
: "${PANEL_URL:?Set PANEL_URL secret}"
: "${PANEL_API_KEY:?Set PANEL_API_KEY secret}"
: "${PANEL_V:?Set PANEL_V variable to v1 or v2}"
: "${DEPLOY_PATH:?Set DEPLOY_PATH variable}"
case "${PANEL_V}" in
  v1|V1|1) api=v1 ;;
  v2|V2|2) api=v2 ;;
  *) echo "PANEL_V must be v1 or v2" >&2; exit 1 ;;
esac
[[ -f mpay-v2-release.tgz ]] || { echo 'Archive missing' >&2; exit 1; }
# Prevent accidentally uploading to root or a generic application directory.
[[ "${DEPLOY_PATH}" == /* && "${DEPLOY_PATH}" != / && "${DEPLOY_PATH}" != /opt && "${DEPLOY_PATH}" != /www ]] || {
  echo "DEPLOY_PATH must be a dedicated absolute MPAY directory" >&2; exit 1;
}
timestamp=$(date +%s)
token=$(printf '1panel%s%s' "${PANEL_API_KEY}" "${timestamp}" | md5sum | awk '{print $1}')
response=$(curl --fail-with-body --silent --show-error --max-time 120 \
  --request POST "${PANEL_URL%/}/api/${api}/files/upload" \
  -H "1Panel-Token: ${token}" -H "1Panel-Timestamp: ${timestamp}" \
  -F "path=${DEPLOY_PATH}" -F "file=@mpay-v2-release.tgz;filename=mpay-v2-release.tgz")
if ! printf '%s' "${response}" | grep -Eq '"code"[[:space:]]*:[[:space:]]*200'; then
  echo "1Panel upload did not return code 200" >&2; exit 1
fi
echo "Archive uploaded to ${DEPLOY_PATH}/mpay-v2-release.tgz"
echo "Staging only: no PHP dependencies installed, no DB migration, no service restarted."
