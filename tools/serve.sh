#!/usr/bin/env bash
# Serve the app with PHP's built-in web server, for development and the
# conformance suite: serve.sh [HOST:PORT]. Production runs php-fpm behind a
# web server instead.
#
# Laravel's router script serves files under public/ directly and sends
# everything else to public/index.php; it expects to run from public/.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root/public"

# Several workers, so one slow request (a password hash) does not stall the rest.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php -d opcache.enable_cli=1 -S "${1:-127.0.0.1:8080}" "$repo_root/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
