# Stack

**Status:** adopted 2026-10-03.

## Decision

- **Back end:** Laravel (`laravel/framework` only), serving both the JSON
  API required by tadmor's `spec/api.md` and the user interface.
- **User interface:** server-rendered Blade templates. No SPA, no npm, no
  Vite, no JavaScript build step. If a small amount of client-side
  behaviour is needed, it is handwritten, or at most one vendored
  JavaScript file (such as htmx), which needs a conversation first.
- **Database:** Postgres 17 with the shared schema from tadmor's
  `db/migrations/`, through PHP's `pdo_pgsql` extension.
- **Runtime:** PHP from the operating system's packages, with its
  extensions and Composer (the OS is out of scope for the metrics):
  PHP 8.4 on the Debian 13 deployment box, PHP 8.5 on the Ubuntu 26.04
  development machine. Dependencies are resolved for 8.4 (below), so
  everything locked runs on both.

## Why Laravel

Laravel is by a wide margin the most heavily used PHP framework, so this
counterpart measures what mainstream PHP actually costs. That makes it a
useful contrast to tadmor-python, where the mainstream framework (Django)
happened to cost almost nothing.

Here it does not. As locked on 2026-10-03 (`laravel/framework` 13.33.0,
resolved for PHP 8.4), the runtime closure is:

| | Packages | Vendors | Packagist maintainer accounts |
| --- | ---: | ---: | ---: |
| `laravel/framework` | **73** | 22 | **37** |

- **Symfony:** 32 packages (console, http-kernel, routing, mailer, mime,
  translation, var-dumper, 11 polyfills, and others), one account.
- **PSR interfaces:** 8 small packages, mostly the PHP-FIG account.
- **About 20 independent maintainers** for the rest: Guzzle (5 accounts),
  Carbon, ramsey/uuid, Flysystem, CommonMark (with nette/schema and
  nette/utils), Monolog, phpdotenv, brick/math, Termwind, cron-expression,
  email-validator, portable-ascii, and so on.

Most of that tree is code tadmor will never call (an HTTP client, cloud
filesystems, Markdown, a terminal UI). It cannot be trimmed:
`laravel/framework` is one Composer package that requires all of it, and
suppressing parts with Composer's `replace` would be fragile and would stop
being Laravel as people use it. The cost is accepted knowingly.

Alternatives measured the same way:

| Option | Packages | Maintainer accounts |
| ------ | -------: | ------------------: |
| Symfony components (http-foundation + routing) | 4 | 1 |
| Slim + slim/psr7 | 11 | 13 |
| tadmor-python (Django, for reference) | 5 | ~4 |
| tadmor, runtime+build (for reference) | 179 | 179 |

Symfony components were the lean option: mainstream, one publisher, and
the foundation Laravel itself is built on. They were passed over in favour
of measuring the framework PHP shops actually choose. Even so, Laravel with
Blade and no npm stays far below tadmor's own total, because tadmor's
figure is dominated by its front-end npm tree.

## Why server-rendered templates

As in tadmor-python: Blade is part of Laravel, so a server-rendered UI
removes the npm tree entirely rather than reproducing it. `spec/README.md`
allows server-rendered pages; the JSON API remains mandatory alongside them.

## Permitted packages

`laravel/framework` and exactly the transitive tree it requires, plus
`phpunit/phpunit` and its tree as a dev dependency, as locked in
`composer.lock`, and nothing else without a conversation first. In
particular:

- **Not the `laravel/laravel` skeleton's extras.** No tinker, pint, sail,
  pail, pao, faker, mockery, or collision.
- **No Vite, no `laravel-vite-plugin`, no `package.json`.**
- **No Laravel first-party add-ons** (Sanctum, Breeze, Fortify, Horizon,
  and so on). Sessions and authentication are our own, over the shared
  `users` and `sessions` tables, as in tadmor.
- **No Pest, Mockery, or Faker.** Plain PHPUnit with Laravel's testing
  helpers is enough.

## Testing

PHPUnit 12, the version the Laravel 13 skeleton targets, with Laravel's
built-in testing helpers (`Illuminate\Foundation\Testing`), which build on
it. Its tree is test-only:

| | Packages | Vendors | Packagist maintainer accounts |
| --- | ---: | ---: | ---: |
| `phpunit/phpunit` 12.5 | 25 | 7 | 6 |
| with `laravel/framework`, everything | 98 | 29 | 42 |

Nearly all of it is Sebastian Bergmann's (`phpunit/*`, `sebastian/*`,
`phar-io/*` jointly with Arne Blankerts' `theseer`); the others are
nikic/php-parser, myclabs/deep-copy, and staabm/side-effects-detector.
The alternative, a small runner of our own, was rejected: Laravel's
testing helpers assume PHPUnit, and this counterpart measures Laravel as
people use it.

It is a `require-dev` dependency, so it is vendored and committed with
the rest but left out of the deployable (`composer install --no-dev` at
deploy time, offline from the committed lock and `vendor/`).

## Supply-chain posture

- **Vendored and committed.** `vendor/` is committed, unmodified, so a
  clean clone runs with no Packagist access. This is the real integrity
  guard: `composer.lock` records dist `shasum`s, but for GitHub-hosted
  packages they are usually empty.
- **Pinned.** Exact versions in `composer.json` (no `^` or `~` ranges);
  `composer.lock` committed. A dependency change is reviewable as a lock
  diff plus a `vendor/` diff.
- **No install-time code.** Composer always runs with `--no-scripts
  --no-plugins`, and `composer.json` sets `"allow-plugins": false`.
- **Cooldown.** No version published less than 7 days ago, as tadmor's
  pnpm policy does. Composer has no such setting, so `tools/vendor.php
  update` (standard library only) re-resolves, excluding each too-recent
  version Composer picked, until every locked version is old enough; a
  root pin that is too recent is an error. (On 2026-10-03 that kept back
  `laravel/framework` 13.34.0 and recent releases of Carbon, Monolog,
  doctrine/lexer, and two Symfony components.) `tools/vendor.php check`
  verifies offline that `composer.json` pins exact versions, that the lock
  matches it, and that `vendor/` holds exactly the locked versions.
- **Hermetic build.** There is no build step: the deployable is the source
  tree plus `vendor/`. A clean clone runs offline with only the PHP
  toolchain (level 3 of tadmor's ladder), and since nothing is compiled or
  bundled, two checkouts of a commit are byte-identical deployables.
  Laravel's cached config, routes, and views are generated at deploy time,
  not committed.
- **Toolchain:** PHP 8.4 or later (`^8.3` is Laravel's floor) with the
  extensions Laravel requires (ctype, filter, hash, mbstring, openssl,
  session, tokenizer) plus pdo_pgsql, all OS packages: on Ubuntu,
  `php8.5-cli php8.5-pgsql php8.5-mbstring php8.5-xml composer`.
  `composer.json` sets `config.platform.php` to Debian 13's 8.4.24, so
  Composer resolves for the deployment box whatever PHP runs it.
- **Serving.** Development and the conformance suite use PHP's built-in
  web server (`tools/serve.sh`) with Laravel's router script, which needs
  no package. Production runs php-fpm behind the web server, both OS
  packages.

## How the application uses Laravel

- **No skeleton.** The app is the handful of files Laravel 13 needs
  (`artisan`, `bootstrap/app.php`, `public/index.php`, `routes/`, and the
  few `config/` files that override the framework's defaults); the
  framework supplies the rest of its configuration.
- **No framework middleware on the API.** `bootstrap/app.php` routes the
  probes and `/api/` without the `web` or `api` groups: no Laravel
  session, cookie encryption, or CSRF token. Every `/api/` error, unknown
  paths included, renders as `{"error": "..."}` there too.
- **Our own sessions.** `app/Services/Sessions.php` keeps sessions in the
  shared `sessions` table (SHA-256 of a random token, fixed 30 days), and
  the `authenticated` middleware re-reads the user on every request.
  Laravel's session, cache, and queue are set to in-memory drivers so the
  framework never wants tables of its own. Passwords are hashed with
  Laravel's Hash (argon2id).
- **Business rules in `app/Services/`**, raising `App\Errors\ApiError`
  with the HTTP status, shared by the JSON API and the UI. Eloquent models
  in `app/Models/` map the shared tables.
- **Commands.** `artisan tadmor:migrate` applies the shared migrations,
  `tadmor:adduser` bootstraps an administrator (password on stdin), and
  `tadmor:resetdb` wipes a database whose name ends in `_test` or
  `_conformance`. PHP serves each request in a fresh process, so unlike
  tadmor the server does not migrate on start: `make run` migrates first,
  and deployment runs `tadmor:migrate`.
- **Tests.** PHPUnit with Laravel's testing helpers. The first test wipes
  the `_test` database and applies the migrations; each test then runs in
  a transaction that is rolled back.

## Consequences for the shared schema

The schema is tadmor's, so Laravel's migrations are not used for it, and
Laravel features that bring their own tables (its database session and
cache drivers, queues' `jobs` table, `password_reset_tokens`) are not used.
Eloquent models map onto the shared tables and views as they are; generated
columns are read-only. Migrations are applied by a small runner of our own
that follows `spec/README.md` (every `*.up.sql` in lexical order, recorded
in `schema_migrations`).

## What would make these choices worth revisiting

- **The measured numbers.** If the counterpart comparison shows Laravel's
  tree dominating in a way that undermines the exercise, a second PHP
  counterpart on Symfony components (`tadmor-php-symfony`) is the natural
  follow-up.
- **A richer client.** If more than a screen or two needs client-side
  behaviour, htmx is the first candidate to discuss.
