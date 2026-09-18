#!/usr/bin/env bash
#
# tests/mysql.sh — a disposable MySQL for the payment-path suite.
#
#   tests/mysql.sh start    start it (and wait until it accepts connections)
#   tests/mysql.sh stop     remove the container and its data
#   tests/mysql.sh run      start it, run the suite against it, stop it
#
# The container is throwaway: it is never networked beyond loopback, holds no
# real data, and `stop` deletes it outright. Nothing here touches the
# application's own database configuration, and the suite refuses any non-
# loopback host regardless of what this script does.

set -euo pipefail

CONTAINER="utiligo-test-mysql"
IMAGE="mysql:8.0"

# Fixed at 3306, deliberately not configurable: userdb.php builds its DSN from
# host, name and charset only, so there is no environment variable for a port and
# the suite would ignore one anyway.
PORT=3306

# A real password, not an empty one. config.php reads credentials as
# `getenv('USERDB_PASS') ?: 'CHANGE_ME'`, so an empty password here silently
# becomes the literal 'CHANGE_ME' and every connection is refused with an
# "Access denied" that says nothing about the real cause.
PW="${UTILIGO_TEST_MYSQL_PW:-utiligo_test_pw}"

export USERDB_HOST=127.0.0.1
export USERDB_NAME=utiligo_test_users
export USERDB_USER=root
export USERDB_PASS="$PW"
export DB_HOST=127.0.0.1
export DB_NAME=utiligo_test_platform
export DB_USER=root
export DB_PASS="$PW"
export APP_ENV=test
export STRIPE_SECRET_KEY=sk_test_stub_key
export STRIPE_WEBHOOK_SECRET=whsec_test_stub_secret
export STRIPE_PRO_PRICE_ID=price_test_pro
export STRIPE_ENT_PRICE_ID=price_test_ent

start() {
  if docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    echo "already running: $CONTAINER"
    return 0
  fi

  docker rm -f "$CONTAINER" >/dev/null 2>&1 || true

  echo "starting $IMAGE on 127.0.0.1:$PORT ..."
  docker run -d --name "$CONTAINER" \
    -p "127.0.0.1:$PORT:3306" \
    -e MYSQL_ROOT_PASSWORD="$PW" \
    -e MYSQL_DATABASE=utiligo_test_users \
    "$IMAGE" >/dev/null

  echo -n "waiting for it to accept connections"
  for _ in $(seq 1 60); do
    # mysqladmin lives inside the container, so this needs no local client. The
    # password is passed because an unauthenticated ping reports the server as
    # down while it is merely refusing the login.
    if docker exec "$CONTAINER" mysqladmin ping -h 127.0.0.1 -u root -p"$PW" --silent >/dev/null 2>&1; then
      echo " ok"
      return 0
    fi
    echo -n "."
    sleep 2
  done

  echo " failed"
  echo "The container started but never became ready. Recent output:" >&2
  docker logs --tail 20 "$CONTAINER" >&2 || true
  return 1
}

stop() {
  docker rm -f "$CONTAINER" >/dev/null 2>&1 && echo "removed $CONTAINER" || echo "nothing to remove"
}

case "${1:-}" in
  start) start ;;
  stop)  stop ;;
  run)
    start
    # --require-db: a green result must mean the entitlement UPDATE really ran.
    php "$(dirname "$0")/run.php" --require-db
    ;;
  *)
    echo "usage: $0 {start|stop|run}" >&2
    exit 2
    ;;
esac
