# tadmor-php

The PHP counterpart of [tadmor](https://github.com/russellw/tadmor): the same
business management software, specified by tadmor's `spec/` and checked by its
`conformance/` suite, built on PHP, Laravel, and Postgres so the stacks can be
compared. See [`docs/stack.md`](docs/stack.md) for the stack and its
supply-chain posture.

## Layout

```
app/               the application: Services (business rules), Http, Models, Console
bootstrap/app.php  Laravel's application setup: routing, middleware, error rendering
config/            the few settings that differ from Laravel's defaults
routes/            probes.php (/healthz, /readyz) and api.php (/api/...)
tests/             PHPUnit tests
tools/             vendor.php (dependencies), serve.sh, conformance.sh
vendor/            all third-party source, committed (tools/vendor.php check)
spec/, conformance/, db/migrations/   copies from tadmor; never edited here
```

## Prerequisites

- **PHP 8.4 or later** with pdo_pgsql, mbstring, and xml, and Composer, from
  the OS. On Ubuntu: `apt install php8.5-cli php8.5-pgsql php8.5-mbstring
  php8.5-xml composer`.
- **Postgres 17** reachable via `DATABASE_URL` (`make db` starts a container).
- **Go**, only to run the conformance suite.

## Build, run, test

There is no build step. Run `make` to list targets:

```sh
make run          # migrate, then serve on HTTP_ADDR (default 127.0.0.1:8080)
make adduser EMAIL=you@example.com NAME='Your Name'   # password on stdin
make test         # PHPUnit (wipes tadmor_php_test)
make conformance  # tadmor's suite against a fresh server (wipes tadmor_php_conformance)
make check        # lint, and verify vendor/ against composer.lock offline
```

Override connection strings on the command line, e.g.
`make run DATABASE_URL=postgres://user:pass@host:5432/db?sslmode=disable`.

## Dependencies

`laravel/framework` and its tree, plus PHPUnit for tests, pinned exactly and
committed in `vendor/`. A clean clone runs offline. Change them only with
`make vendor-update`, which enforces a 7-day release cooldown and runs Composer
without scripts or plugins; see [`docs/stack.md`](docs/stack.md).
