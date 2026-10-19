#!/usr/bin/env bash
# Build one disposable WordPress fixture. Never modifies application Compose/DB.
set -euo pipefail
: "${QA_NETWORK:?Set the existing pilot Docker network}"
: "${QA_DB_HOST:?Set existing MariaDB hostname and optional port}"
: "${QA_DB_NAME:?Create a fresh chilli_wp_qa_<unique-id> schema first}"
: "${QA_DB_USER:?Create a separate scratch-only database user first}"
: "${QA_DB_PASSWORD_FILE:?Set absolute scratch database password file path}"
: "${QA_ADMIN_PASSWORD_FILE:?Set absolute scratch WordPress admin password file path}"
: "${CHILLI_QA_SITE_KEY:?Set a disposable central service website key}"
[[ "$QA_DB_NAME" =~ ^chilli_wp_qa_[a-z0-9_]+$ ]] || { echo 'Refusing non-QA database name' >&2; exit 1; }
[[ "$QA_DB_USER" =~ ^chilli_wp_qa_[a-z0-9_]+$ ]] || { echo 'Refusing non-QA database user' >&2; exit 1; }
[[ -f "$QA_DB_PASSWORD_FILE" && -f "$QA_ADMIN_PASSWORD_FILE" ]] || { echo 'Missing scratch password files' >&2; exit 1; }
QA_ROOT=$(cd "$(dirname "$0")/.." && pwd -P)
QA_IMAGE=${QA_IMAGE:-wordpress:cli-php8.3}
QA_NAME=${QA_NAME:-"chilli-wp-qa-$(date -u +%Y%m%d%H%M%S)-${RANDOM}"}
QA_VOLUME="${QA_NAME}-files-${RANDOM}"
if docker container inspect "$QA_NAME" >/dev/null 2>&1; then
  echo "Refusing to replace an existing QA container" >&2; exit 1
fi
QA_BIND_PORT=${QA_BIND_PORT:-3092}
QA_PUBLIC_URL=${QA_PUBLIC_URL:-https://consent.chillimedia.se/__wordpress_qa}
CHILLI_QA_EXPECT_VERIFIED=${CHILLI_QA_EXPECT_VERIFIED:-1}
export CHILLI_QA_SITE_KEY CHILLI_QA_EXPECT_VERIFIED
created_volume=0
cleanup_failed() {
  local code=$?
  if [[ "$code" -ne 0 ]]; then
    docker rm -f "$QA_NAME" >/dev/null 2>&1 || true
    if [[ "$created_volume" == 1 ]]; then docker volume rm "$QA_VOLUME" >/dev/null 2>&1 || true; fi
    echo 'QA setup failed; only its own container/volume were removed. Scratch schema/user/password-file cleanup remains with the operator.' >&2
  fi
}
trap cleanup_failed EXIT

docker pull "$QA_IMAGE"
QA_IMAGE_ID=$(docker image inspect --format '{{.Id}}' "$QA_IMAGE")
docker volume create "$QA_VOLUME" >/dev/null
created_volume=1
qa_wp() {
  docker run --rm -i --cap-drop ALL --security-opt no-new-privileges \
    --memory 256m --cpus 0.5 --pids-limit 80 --network "$QA_NETWORK" \
    --mount "type=volume,src=$QA_VOLUME,dst=/var/www/html" \
    --mount "type=bind,src=$QA_ROOT,dst=/qa,readonly" \
    -e CHILLI_QA_SITE_KEY -e CHILLI_QA_EXPECT_VERIFIED \
    --entrypoint php "$QA_IMAGE_ID" -d memory_limit=192M /usr/local/bin/wp "$@"
}
qa_wp core download https://downloads.wordpress.org/release/wordpress-7.1.3.zip
qa_wp core verify-checksums --version=7.1.3
qa_wp config create --dbname="$QA_DB_NAME" --dbuser="$QA_DB_USER" \
  --dbhost="$QA_DB_HOST" --dbprefix=chilliqa_ --skip-check --prompt=dbpass < "$QA_DB_PASSWORD_FILE" >/dev/null 2>&1 || { echo "Scratch wp-config creation failed; credential-bearing command output withheld" >&2; exit 1; }
qa_wp config set CONZENT_AUTO_SETUP false --raw
qa_wp config set DISABLE_WP_CRON true --raw
qa_wp config set WP_ENVIRONMENT_TYPE development
qa_wp config set FORCE_SSL_ADMIN true --raw
docker run --rm --cap-drop ALL --security-opt no-new-privileges \
  --mount "type=volume,src=$QA_VOLUME,dst=/var/www/html" \
  --mount "type=bind,src=$QA_ROOT/tests,dst=/qa,readonly" \
  --entrypoint php "$QA_IMAGE_ID" /qa/qa-config-proxy.php
qa_wp core install --url="$QA_PUBLIC_URL" --title='Chilli Consent disposable QA' \
  --admin_user=qaadmin --admin_email=qa@example.invalid --skip-email \
  --prompt=admin_password < "$QA_ADMIN_PASSWORD_FILE" >/dev/null 2>&1 || { echo "Scratch WordPress installation failed; credential-bearing command output withheld" >&2; exit 1; }
qa_wp option update blog_public 0
qa_wp plugin install wp-consent-api --version=2.1.0 --activate
qa_wp plugin install /qa/chilli-consent.zip --activate
qa_wp eval-file /qa/tests/real-wordpress-check.php

docker run --rm --cap-drop ALL --security-opt no-new-privileges \
  --mount "type=volume,src=$QA_VOLUME,dst=/var/www/html" \
  --mount "type=bind,src=$QA_ROOT/tests,dst=/qa,readonly" \
  --entrypoint sh "$QA_IMAGE_ID" -c \
  'mkdir -p wp-content/mu-plugins && cp /qa/consumer-bridge-mu.php wp-content/mu-plugins/chilli-consent-qa.php && cp /qa/banner-observer-mu.php wp-content/mu-plugins/chilli-banner-observer.php'
docker run -d --name "$QA_NAME" --cap-drop ALL --security-opt no-new-privileges \
  --memory 256m --cpus 0.5 --pids-limit 80 --network "$QA_NETWORK" \
  -p "127.0.0.1:$QA_BIND_PORT:8080" \
  --mount "type=volume,src=$QA_VOLUME,dst=/var/www/html" \
  "$QA_IMAGE_ID" wp server --host=0.0.0.0 --port=8080 >/dev/null
printf 'QA_CONTAINER=%s\nQA_VOLUME=%s\nQA_IMAGE_ID=%s\nQA_BROWSER_URL=%s/?chilli-consent-qa=1#chilli-consent-qa-results\n' \
  "$QA_NAME" "$QA_VOLUME" "$QA_IMAGE_ID" "$QA_PUBLIC_URL"
echo 'Fixture is running on loopback only. Add the temporary nginx prefix route, inspect the browser checks, then remove this container/volume and the scratch schema/user/password files.'
