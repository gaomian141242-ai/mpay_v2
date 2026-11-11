#!/usr/bin/env bash
set -Eeuo pipefail

BASE=/opt/mpay-v2
RELEASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SHARED="$BASE/shared"
BACKUPS="$BASE/backups"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$BACKUPS/$STAMP"
IMAGE="mpay-v2:$SOURCE_SHA"
CANDIDATE="mpay-v2-candidate"
ACTIVE="mpay-v2-php"
OLD_CONTAINER=""
CANDIDATE_STARTED=0
NEW_ACTIVE_STARTED=0
DEPLOY_OK=0

log() { printf '[MPAY-DEPLOY] %s\n' "$*"; }
fail() { log "ERROR: $*"; exit 1; }

rollback() {
  rc=$?
  if [ "$DEPLOY_OK" -eq 1 ]; then return; fi
  log "Deployment failed (exit=$rc); attempting rollback"
  docker rm -f "$CANDIDATE" >/dev/null 2>&1 || true
  if [ "$CANDIDATE_STARTED" -eq 1 ] || [ "$NEW_ACTIVE_STARTED" -eq 1 ]; then
    docker rm -f "$ACTIVE" >/dev/null 2>&1 || true
  fi
  if [ -n "$OLD_CONTAINER" ]; then
    if docker inspect "$OLD_CONTAINER" >/dev/null 2>&1; then
      docker rename "$OLD_CONTAINER" "$ACTIVE" >/dev/null 2>&1 || true
      docker start "$ACTIVE" >/dev/null 2>&1 || true
    fi
  fi
  log "Rollback attempted. Backup retained at $BACKUP"
}
trap rollback EXIT

mkdir -p "$BACKUP" "$SHARED/keys" "$SHARED/runtime"
chmod 700 "$BACKUPS" "$BACKUP" "$SHARED" "$SHARED/keys" "$SHARED/runtime"

# Preserve existing deployment files and credentials before any change.
if [ -d "$BASE/releases/v1" ]; then
  tar -czf "$BACKUP/previous-release-v1.tgz" -C "$BASE/releases" v1
fi
if [ -f "$BASE/releases/v1/.env" ]; then cp -a "$BASE/releases/v1/.env" "$BACKUP/previous.env"; fi
for keyfile in epay-platform-private.pem epay-platform-public.pem; do
  if [ -f "$BASE/releases/v1/$keyfile" ]; then cp -a "$BASE/releases/v1/$keyfile" "$BACKUP/"; fi
done

MYSQL_CID="$(docker ps -q --filter 'name=1Panel-mysql' | head -n1)"
REDIS_CID="$(docker ps -q --filter 'name=1Panel-redis' | head -n1)"
[ -n "$MYSQL_CID" ] || fail "Existing 1Panel MySQL container not found"
[ -n "$REDIS_CID" ] || fail "Existing 1Panel Redis container not found"
docker network inspect 1panel-network >/dev/null 2>&1 || fail "1panel-network not found"

# Ensure existing data services are reachable on the dedicated application network.
for cid in "$MYSQL_CID" "$REDIS_CID"; do
  if ! docker inspect -f '{{json .NetworkSettings.Networks}}' "$cid" | grep -q '"1panel-network"'; then
    docker network connect 1panel-network "$cid"
  fi
done
MYSQL_HOST="$(docker inspect -f '{{.Name}}' "$MYSQL_CID" | sed 's#^/##')"
REDIS_HOST="$(docker inspect -f '{{.Name}}' "$REDIS_CID" | sed 's#^/##')"

MYSQL_ROOT_PASSWORD="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$MYSQL_CID" | sed -n 's/^MYSQL_ROOT_PASSWORD=//p' | head -n1)"
[ -n "$MYSQL_ROOT_PASSWORD" ] || fail "Cannot obtain MySQL root credential from container environment"
REDIS_PASSWORD="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$REDIS_CID" | sed -n 's/^REDIS_PASSWORD=//p' | head -n1)"
if [ -z "$REDIS_PASSWORD" ]; then
  if ! docker exec "$REDIS_CID" redis-cli ping 2>/dev/null | grep -q PONG; then
    fail "Redis requires authentication but REDIS_PASSWORD was not found in container environment"
  fi
fi

DB_USER="mpay_app"
DB_PASSWORD="$(openssl rand -hex 32)"
ADMIN_USER="admin"
ADMIN_PASSWORD="$(openssl rand -hex 20)"
JWT_MAIN="$(openssl rand -hex 32)"
JWT_ADMIN="$(openssl rand -hex 32)"
JWT_MERCHANT="$(openssl rand -hex 32)"

MYSQL=(docker exec -e "MYSQL_PWD=$MYSQL_ROOT_PASSWORD" "$MYSQL_CID" mysql -uroot --batch --skip-column-names)
"${MYSQL[@]}" -e "CREATE DATABASE IF NOT EXISTS mpay CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASSWORD'; ALTER USER '$DB_USER'@'%' IDENTIFIED BY '$DB_PASSWORD'; GRANT ALL PRIVILEGES ON mpay.* TO '$DB_USER'@'%'; GRANT CREATE ON *.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;"
unset MYSQL_ROOT_PASSWORD

# InstallService writes this mounted file; three independent JWT secrets are generated per deployment.
cat > "$RELEASE_DIR/.env" <<ENV
DB_HOST=$MYSQL_HOST
DB_PORT=3306
DB_DATABASE=mpay
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASSWORD
REDIS_HOST=$REDIS_HOST
REDIS_PORT=6379
REDIS_PASSWORD=$REDIS_PASSWORD
REDIS_DATABASE=0
QUEUE_DATABASE=1
CACHE_DRIVER=redis
AUTH_JWT_ISSUER=mpay
AUTH_JWT_LEEWAY=30
AUTH_JWT_SECRET=$JWT_MAIN
AUTH_ADMIN_JWT_SECRET=$JWT_ADMIN
AUTH_ADMIN_JWT_TTL=86400
AUTH_ADMIN_JWT_REDIS_PREFIX=mpay:auth:admin:
AUTH_MERCHANT_JWT_SECRET=$JWT_MERCHANT
AUTH_MERCHANT_JWT_TTL=86400
AUTH_MERCHANT_JWT_REDIS_PREFIX=mpay:auth:merchant:
ENV
chmod 600 "$RELEASE_DIR/.env"
cp "$RELEASE_DIR/.env" "$BACKUP/generated.env"

docker build -f "$RELEASE_DIR/deploy/php.Dockerfile" -t "$IMAGE" "$RELEASE_DIR"
docker rm -f "$CANDIDATE" >/dev/null 2>&1 || true

if docker inspect "$ACTIVE" >/dev/null 2>&1; then
  OLD_CONTAINER="mpay-v2-rollback-$STAMP"
  docker stop "$ACTIVE" >/dev/null
  docker rename "$ACTIVE" "$OLD_CONTAINER"
fi

docker run -d --name "$CANDIDATE" --restart unless-stopped \
  --network 1panel-network -p 0.0.0.0:18787:8787 \
  --env-file "$RELEASE_DIR/.env" \
  -e MPAY_DOCKER_SKIP_AUTO_RESTART=1 \
  -e MPAY_SKIP_DEFAULT_MERCHANT_SEEDER=1 \
  -e EPAY_PLATFORM_KEY_DIR=/data/keys \
  -v "$RELEASE_DIR/.env:/app/.env" \
  -v "$SHARED/keys:/data/keys" \
  -v "$SHARED/runtime:/app/runtime" \
  "$IMAGE" >/dev/null
CANDIDATE_STARTED=1

# Wait for the install endpoint to answer before submitting the one-time installer.
STATUS=""
for _ in $(seq 1 60); do
  STATUS="$(curl -fsS http://127.0.0.1:18787/adminapi/install/status 2>/dev/null || true)"
  if [ -n "$STATUS" ]; then break; fi
  sleep 2
done
[ -n "$STATUS" ] || { docker logs "$CANDIDATE" --tail 100; fail "Install status endpoint did not become ready"; }
printf '%s' "$STATUS" | jq -e '.code == 200 and .data.installed == false' >/dev/null || {
  printf '%s\n' "$STATUS" | jq 'del(.data)' || true
  fail "Fresh installation status was not reported; refusing to overwrite existing install"
}

SITE_URL="http://$SSH_HOST:18787"
PAYLOAD="$(jq -n \
  --arg site_name "MPAY V2" --arg site_url "$SITE_URL" \
  --arg db_host "$MYSQL_HOST" --arg db_port "3306" \
  --arg db_database "mpay" --arg db_username "$DB_USER" --arg db_password "$DB_PASSWORD" \
  --arg redis_host "$REDIS_HOST" --arg redis_port "6379" --arg redis_password "$REDIS_PASSWORD" \
  --arg admin_username "$ADMIN_USER" --arg admin_password "$ADMIN_PASSWORD" \
  --arg auth_jwt_secret "$JWT_MAIN" --arg auth_admin_jwt_secret "$JWT_ADMIN" \
  --arg auth_merchant_jwt_secret "$JWT_MERCHANT" \
  '{site_name:$site_name,site_url:$site_url,db_host:$db_host,db_port:$db_port,db_database:$db_database,db_username:$db_username,db_password:$db_password,redis_host:$redis_host,redis_port:$redis_port,redis_password:$redis_password,redis_database:0,queue_database:1,admin_username:$admin_username,admin_password:$admin_password,admin_password_confirm:$admin_password,admin_real_name:"MPAY Administrator",auth_jwt_secret:$auth_jwt_secret,auth_admin_jwt_secret:$auth_admin_jwt_secret,auth_merchant_jwt_secret:$auth_merchant_jwt_secret}')"
INSTALL_RESPONSE="$(curl -fsS -X POST http://127.0.0.1:18787/adminapi/install/run -H 'Content-Type: application/json' --data "$PAYLOAD")" || fail "Install endpoint request failed"
printf '%s' "$INSTALL_RESPONSE" | jq -e '.code == 200' >/dev/null || {
  printf '%s\n' "$INSTALL_RESPONSE" | jq '{code,msg}' || true
  fail "InstallService returned failure"
}
unset PAYLOAD DB_PASSWORD JWT_MAIN JWT_ADMIN JWT_MERCHANT
# InstallService needs CREATE DATABASE permission; remove the temporary global privilege now.
MYSQL_ROOT_PASSWORD="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$MYSQL_CID" | sed -n 's/^MYSQL_ROOT_PASSWORD=//p' | head -n1)"
docker exec -e "MYSQL_PWD=$MYSQL_ROOT_PASSWORD" "$MYSQL_CID" mysql -uroot -e "REVOKE CREATE ON *.* FROM '$DB_USER'@'%'; FLUSH PRIVILEGES;"
unset MYSQL_ROOT_PASSWORD

docker stop "$CANDIDATE" >/dev/null
docker rm "$CANDIDATE" >/dev/null
docker run -d --name "$ACTIVE" --restart unless-stopped \
  --network 1panel-network -p 0.0.0.0:18787:8787 \
  --env-file "$RELEASE_DIR/.env" \
  -e MPAY_DOCKER_SKIP_AUTO_RESTART=1 \
  -e MPAY_SKIP_DEFAULT_MERCHANT_SEEDER=1 \
  -e EPAY_PLATFORM_KEY_DIR=/data/keys \
  -v "$RELEASE_DIR/.env:/app/.env" \
  -v "$SHARED/keys:/data/keys" \
  -v "$SHARED/runtime:/app/runtime" \
  "$IMAGE" >/dev/null
NEW_ACTIVE_STARTED=1
CANDIDATE_STARTED=0

# Verify HTTP app, install lock, RSA material, DB schema and all application processes.
HEALTH=""
for _ in $(seq 1 60); do
  HEALTH="$(curl -fsS http://127.0.0.1:18787/adminapi/install/status 2>/dev/null || true)"
  if printf '%s' "$HEALTH" | jq -e '.code == 200 and .data.installed == true and .data.database_installed == true' >/dev/null 2>&1; then break; fi
  sleep 2
done
printf '%s' "$HEALTH" | jq -e '.code == 200 and .data.installed == true and .data.database_installed == true' >/dev/null || {
  docker logs "$ACTIVE" --tail 120
  fail "Post-install health check failed"
}
docker exec "$ACTIVE" test -s /app/runtime/install.lock
docker exec "$ACTIVE" test -s /data/keys/epay-platform-private.pem
docker exec "$ACTIVE" test -s /data/keys/epay-platform-public.pem
docker exec "$ACTIVE" php -r 'require "/app/vendor/autoload.php"; echo "autoload OK\n";'
docker top "$ACTIVE" -eo comm | grep -q php
TABLE_COUNT="$("${MYSQL[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='mpay';")"
[ "$TABLE_COUNT" -gt 0 ] || fail "Database migration produced no tables"
curl -fsS http://127.0.0.1:18787/ >/dev/null || fail "Public home route failed"

# Keep credentials only in root-readable files; never print them into Actions logs.
printf '%s\n' "$ADMIN_USER" > "$SHARED/admin-username.txt"
printf '%s\n' "$ADMIN_PASSWORD" > "$SHARED/admin-password.txt"
chmod 600 "$SHARED/admin-username.txt" "$SHARED/admin-password.txt"
printf '%s\n' "$SOURCE_SHA" > "$SHARED/deployed-commit.txt"
unset ADMIN_PASSWORD
if [ -n "$OLD_CONTAINER" ]; then docker update --restart=no "$OLD_CONTAINER" >/dev/null 2>&1 || true; fi
DEPLOY_OK=1
log "SUCCESS: MPAY V2 deployed. URL=$SITE_URL/admin; DB tables=$TABLE_COUNT; rollback=$BACKUP"
