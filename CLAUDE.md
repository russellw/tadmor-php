The goal of this project is to develop comprehensive business management software.
It is the PHP counterpart of tadmor (~/tadmor): the same product, specified by
tadmor's spec/ and checked by its conformance/ suite, built on a different stack so
the two can be compared (see ~/tadmor/docs/counterpart-metrics.md).

Technology stack:
Postgres for the database, using the shared schema from tadmor's db/migrations.
PHP and Laravel for the back end.
Server-rendered Blade templates for the user interface; no npm, no Vite, no JavaScript build.
See docs/stack.md for the decision and its rationale.

Schema design:
The schema is shared with tadmor and is not ours to redesign. Eloquent models map onto
it as it is; Laravel's own migration system is not used for it.

Dependencies:
Supply-chain conscious throughout; keep the third-party footprint small, pinned, and
reviewable in-repo. The only permitted third-party packages are laravel/framework and
the tree it requires, plus phpunit/phpunit for tests, as described in docs/stack.md. New packages need a conversation
first. vendor/ is committed; Composer runs only with --no-scripts --no-plugins, and
nothing is installed from Packagist at build or run time.

Working on it:
Business rules live in app/Services/, shared by the JSON API (app/Http/Controllers/Api/)
and the HTML UI (app/Http/Controllers/Ui/). Never put a rule in a controller.
The Content Security Policy forbids inline styles and scripts; use public/app.css and app.js.
spec/, conformance/, and db/migrations/ are copies from tadmor (spec/UPSTREAM);
never edit them here. Re-export from tadmor with spec/export.sh.
Change dependencies only through `make vendor-update` (tools/vendor.php), never by
running composer directly. It also rewrites dependencies.json; commit it with the lock and vendor/.
Before committing, run `make check`, `make test`, and `make conformance`; all must pass.

Version control:
Commit directly to the default branch. Do not create feature branches.
