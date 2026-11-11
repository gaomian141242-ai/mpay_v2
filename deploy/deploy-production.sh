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
DB_BACKUP_FILE="$BACKUP/mpay-before-clean.sql"
DB_BACKUP_PRESENT=0
DB_RESET_STARTED=0

log() { printf '[MPAY-DEPLOY] %s\n' "$*"; }
fail() { log "ERROR: $*"; exit 1; }

rollback() {
  rc=$?
  if [ "$DEPLOY_OK" -eq 1 ]; then return; fi
  log "Deployment failed (exit=$rc); attempting rollback"
  if [ "$DB_RESET_STARTED" -eq 1 ] && [ -n "${MYSQL_ROOT_PASSWORD:-}" ] && [ -n "${MYSQL_CID:-}" ]; then
    log "Restoring pre-deployment MPAY database backup"
    docker exec -e "MYSQL_PWD=$MYSQL_ROOT_PASSWORD" "$MYSQL_CID" mysql -uroot -e "DROP DATABASE IF EXISTS mpay; CREATE DATABASE mpay CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" >/dev/null 2>&1 || true
    if [ "$DB_BACKUP_PRESENT" -eq 1 ] && [ -s "$DB_BACKUP_FILE" ]; then
      docker exec -i -e "MYSQL_PWD=$MYSQL_ROOT_PASSWORD" "$MYSQL_CID" mysql -uroot mpay < "$DB_BACKUP_FILE" >/dev/null 2>&1 || log "WARNING: automatic database restore failed; SQL dump retained at $DB_BACKUP_FILE"
    fi
  fi
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
if [ -f "$RELEASE_DIR/.env" ]; then cp -a "$RELEASE_DIR/.env" "$BACKUP/previous-release.env"; fi
tar -czf "$BACKUP/mpay-shared-state.tgz" -C "$SHARED" keys runtime 2>/dev/null || fail "Could not back up MPAY keys/runtime before clean install"
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
# 1Panel may inject the Redis password as a command argument or keep it in
# the mounted redis.conf / application .env instead of the container environment.
REDIS_PASSWORD="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$REDIS_CID" | sed -n 's/^REDIS_PASSWORD=//p' | head -n1)"

mapfile -t REDIS_CMD_ARGS < <(docker inspect "$REDIS_CID" | jq -r '.[0].Config | ((.Entrypoint // []) + (.Cmd // []))[]?')
if [ -z "$REDIS_PASSWORD" ]; then
  for ((i=0; i<${#REDIS_CMD_ARGS[@]}; i++)); do
    arg="${REDIS_CMD_ARGS[$i]}"
    case "$arg" in
      --requirepass)
        if [ $((i + 1)) -lt ${#REDIS_CMD_ARGS[@]} ]; then REDIS_PASSWORD="${REDIS_CMD_ARGS[$((i + 1))]}"; fi
        break
        ;;
      --requirepass=*)
        REDIS_PASSWORD="${arg#*=}"
        break
        ;;
    esac
  done
fi

# Look for the active Redis config path from the container command, then
# check common paths used by official Redis and 1Panel images.
REDIS_CONFIG_FILES=(/etc/redis/redis.conf /usr/local/etc/redis/redis.conf /etc/redis.conf /data/redis.conf)
for arg in "${REDIS_CMD_ARGS[@]}"; do
  case "$arg" in
    /*.conf) REDIS_CONFIG_FILES+=("$arg") ;;
  esac
done
if [ -z "$REDIS_PASSWORD" ]; then
  REDIS_PASSWORD="$(docker exec "$REDIS_CID" sh -c '
    for file do
      [ -r "$file" ] || continue
      awk -v key=requirepass "\$1 == key {print \$2; exit}" "$file"
    done
  ' sh "${REDIS_CONFIG_FILES[@]}" 2>/dev/null | head -n1 || true)"
fi

# 1Panel stores app-level variables beside the mounted data/config directory.
if [ -z "$REDIS_PASSWORD" ]; then
  while IFS= read -r mount_source; do
    [ -n "$mount_source" ] || continue
    dir="$mount_source"
    for _ in 1 2 3; do
      dir="$(dirname "$dir")"
      for env_file in "$dir/.env" "$dir/.env.production"; do
        [ -r "$env_file" ] || continue
        value="$(sed -n -E 's/^(REDIS_PASSWORD|REDIS_PASS|REDIS_ROOT_PASSWORD)=//p' "$env_file" | head -n1)"
        value="${value%\"}"; value="${value#\"}"
        value="${value%\'}"; value="${value#\'}"
        if [ -n "$value" ]; then REDIS_PASSWORD="$value"; break 3; fi
      done
    done
  done < <(docker inspect "$REDIS_CID" | jq -r '.[0].Mounts[]?.Source')
fi

if [ -z "$REDIS_PASSWORD" ]; then
  if ! docker exec "$REDIS_CID" redis-cli ping 2>/dev/null | grep -q PONG; then
    fail "Redis authentication is enabled, but its password could not be read from the 1Panel environment, command, or Redis config"
  fi
fi

# Fail early if a discovered password is stale or does not match the live Redis.
if [ -n "$REDIS_PASSWORD" ]; then
  docker exec "$REDIS_CID" redis-cli --no-auth-warning -a "$REDIS_PASSWORD" ping 2>/dev/null | grep -q PONG \
    || fail "Redis password was discovered but authentication validation failed"
fi

DB_USER="mpay_app"
DB_PASSWORD="$(openssl rand -hex 32)"
ADMIN_USER="admin"
ADMIN_PASSWORD="$(openssl rand -hex 20)"
JWT_MAIN="$(openssl rand -hex 32)"
JWT_ADMIN="$(openssl rand -hex 32)"
JWT_MERCHANT="$(openssl rand -hex 32)"

MYSQL=(docker exec -e "MYSQL_PWD=$MYSQL_ROOT_PASSWORD" "$MYSQL_CID" mysql -uroot --batch --skip-column-names)
# Back up the existing MPAY schema, then recreate ONLY the mpay database.
DB_EXISTS="$("${MYSQL[@]}" -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='mpay';")"
if [ "$DB_EXISTS" = "1" ]; then
  docker exec -e "MYSQL_PWD=$MYSQL_ROOT_PASSWORD" "$MYSQL_CID" mysqldump -uroot --single-transaction --routines --triggers --events mpay > "$DB_BACKUP_FILE"
  [ -s "$DB_BACKUP_FILE" ] || fail "MPAY database backup is empty; refusing destructive reset"
  DB_BACKUP_PRESENT=1
fi
DB_RESET_STARTED=1
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS mpay; CREATE DATABASE mpay CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASSWORD'; ALTER USER '$DB_USER'@'%' IDENTIFIED BY '$DB_PASSWORD'; GRANT ALL PRIVILEGES ON mpay.* TO '$DB_USER'@'%'; GRANT CREATE ON *.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;"
# Reset MPAY's install marker and platform keypair only; shared Redis and other apps are untouched.
rm -f "$SHARED/runtime/install.lock"
rm -f "$SHARED/keys/epay-platform-private.pem" "$SHARED/keys/epay-platform-public.pem"

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
printf '%s' "$STATUS" | jq -e '.code == 200 and .data.installed == false' >/dev/null 2>&1 || {
  printf '%s\\n' "$STATUS" | jq '{code,msg,data:{installed:.data.installed,database_installed:.data.database_installed,database_exists:.data.database_exists,core_tables:.data.core_tables}}' 2>/dev/null || true
  fail "Clean MPAY database did not report a fresh install state"
}
FRESH_INSTALL=1

if [ "$FRESH_INSTALL" -eq 1 ]; then
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
fi
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
  if printf '%s' "$HEALTH" | jq -e '.code == 200 and .data.installed == true' >/dev/null 2>&1; then break; fi
  sleep 2
done
if ! printf '%s' "$HEALTH" | jq -e '.code == 200 and .data.installed == true' >/dev/null 2>&1; then
  log "Install status endpoint response (safe fields only):"
  printf '%s\n' "$HEALTH" | jq '{code,msg,data:{installed:.data.installed,database_installed:.data.database_installed,database_exists:.data.database_exists,core_tables:.data.core_tables}}' 2>/dev/null || printf '%s\n' "$HEALTH"
  docker logs "$ACTIVE" --tail 120
  fail "Post-install HTTP/install-lock health check failed"
fi
docker exec "$ACTIVE" test -s /app/runtime/install.lock
docker exec "$ACTIVE" test -s /data/keys/epay-platform-private.pem
docker exec "$ACTIVE" test -s /data/keys/epay-platform-public.pem
docker exec "$ACTIVE" php -r 'require "/app/vendor/autoload.php"; echo "autoload OK\n";'
docker top "$ACTIVE" -eo comm | grep -q php
TABLE_COUNT="$("${MYSQL[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='mpay';")"
[ "$TABLE_COUNT" -gt 0 ] || fail "Database migration produced no tables"
INSTALL_STATE="$("${MYSQL[@]}" -e "SELECT config_value FROM mpay.ma_system_config WHERE config_key='install_status' LIMIT 1;")"
[ "$INSTALL_STATE" = "installed" ] || fail "Database install_status is not installed"
curl -fsS http://127.0.0.1:18787/ >/dev/null || fail "Public home route failed"

# Keep credentials only in root-readable files; never print them into Actions logs.
printf '%s\n' "$ADMIN_USER" > "$SHARED/admin-username.txt"
printf '%s\n' "$ADMIN_PASSWORD" > "$SHARED/admin-password.txt"
chmod 600 "$SHARED/admin-username.txt" "$SHARED/admin-password.txt"
printf '%s\n' "$SOURCE_SHA" > "$SHARED/deployed-commit.txt"
unset ADMIN_PASSWORD
if [ -n "$OLD_CONTAINER" ]; then docker update --restart=no "$OLD_CONTAINER" >/dev/null 2>&1 || true; fi
DEPLOY_OK=1
DB_RESET_STARTED=0
log "SUCCESS: MPAY V2 deployed. URL=$SITE_URL/admin; DB tables=$TABLE_COUNT; rollback=$BACKUP"
