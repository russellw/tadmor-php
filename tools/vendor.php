<?php
// Manage the committed vendor/ tree (see docs/stack.md).
//
//   php tools/vendor.php update   Resolve composer.json into composer.lock under
//                                 the 7-day cooldown, then install into vendor/.
//                                 Needs the network.
//   php tools/vendor.php check    Verify, offline, that composer.json pins exact
//                                 versions, that composer.lock matches it, and
//                                 that vendor/ holds exactly the locked versions.
//
// Composer runs only with --no-scripts --no-plugins. The cooldown: no package
// version published less than COOLDOWN_DAYS ago is locked. Composer has no such
// setting, so update re-resolves, excluding each too-recent version it picked,
// until every locked version is old enough.

declare(strict_types=1);

const COOLDOWN_DAYS = 7;

$root = dirname(__DIR__);
chdir($root);

function fail(string $msg): never
{
    fwrite(STDERR, "vendor.php: $msg\n");
    exit(1);
}

function readJson(string $path): array
{
    $text = @file_get_contents($path);
    if ($text === false) {
        fail("cannot read $path");
    }
    return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
}

function composer(array $args): void
{
    $cmd = array_merge(['composer', '--no-interaction', '--no-scripts', '--no-plugins'], $args);
    passthru(implode(' ', array_map('escapeshellarg', $cmd)), $code);
    if ($code !== 0) {
        fail('composer failed: ' . implode(' ', $args));
    }
}

/** @return array<string, array> every locked package, runtime and dev, by name */
function lockedPackages(): array
{
    $lock = readJson('composer.lock');
    $out = [];
    foreach (array_merge($lock['packages'], $lock['packages-dev']) as $p) {
        $out[$p['name']] = $p;
    }
    return $out;
}

function update(): void
{
    $manifest = readJson('composer.json');
    $roots = array_merge($manifest['require'] ?? [], $manifest['require-dev'] ?? []);
    $cutoff = time() - COOLDOWN_DAYS * 86400;
    $exclude = []; // package => list of versions to skip

    for ($round = 1; ; $round++) {
        $with = [];
        foreach ($exclude as $name => $versions) {
            $with[] = '--with=' . $name . ':' . implode(',', array_map(fn ($v) => "!=$v", $versions));
        }
        composer(array_merge(['update', '--no-install', '--quiet'], $with));

        $fresh = [];
        foreach (lockedPackages() as $name => $p) {
            $time = strtotime($p['time'] ?? '');
            if ($time === false) {
                fail("$name {$p['version']} has no release time in composer.lock");
            }
            if ($time > $cutoff) {
                $fresh[$name] = $p['version'];
            }
        }
        if (!$fresh) {
            break;
        }
        foreach ($fresh as $name => $version) {
            if (isset($roots[$name])) {
                fail("$name $version is pinned in composer.json but is under " . COOLDOWN_DAYS . ' days old');
            }
            echo "cooldown: $name $version is under " . COOLDOWN_DAYS . " days old, re-resolving without it\n";
            $exclude[$name][] = $version;
        }
        if ($round >= 50) {
            fail('cooldown resolution did not settle');
        }
    }

    composer(['install']);
    check();
}

function check(): void
{
    $errors = [];
    $manifest = readJson('composer.json');
    foreach (['require', 'require-dev'] as $section) {
        foreach ($manifest[$section] ?? [] as $name => $constraint) {
            if (str_contains($name, '/') && !preg_match('/^\d+\.\d+\.\d+$/', $constraint)) {
                $errors[] = "composer.json: $name must be pinned to an exact version, not \"$constraint\"";
            }
        }
    }
    if (($manifest['config']['allow-plugins'] ?? null) !== false) {
        $errors[] = 'composer.json: config.allow-plugins must be false';
    }

    // The lock's content-hash covers the fields of composer.json that affect
    // resolution; this is the comparison `composer validate` makes.
    $lock = readJson('composer.lock');
    $relevant = array_intersect_key($manifest, array_flip([
        'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
        'minimum-stability', 'prefer-stable', 'repositories', 'extra',
    ]));
    if (isset($manifest['config']['platform'])) {
        $relevant['config']['platform'] = $manifest['config']['platform'];
    }
    ksort($relevant);
    $hash = md5(json_encode($relevant));
    if ($hash !== $lock['content-hash']) {
        $errors[] = 'composer.lock is out of date with composer.json; run `make vendor-update`';
    }

    $locked = lockedPackages();
    $installed = [];
    foreach (readJson('vendor/composer/installed.json')['packages'] as $p) {
        $installed[$p['name']] = $p;
    }
    foreach ($locked as $name => $p) {
        if (!isset($installed[$name])) {
            $errors[] = "vendor/: $name is locked but not installed";
        } elseif ($installed[$name]['version'] !== $p['version']
            || ($installed[$name]['dist']['reference'] ?? null) !== ($p['dist']['reference'] ?? null)) {
            $errors[] = "vendor/: $name is {$installed[$name]['version']}, locked {$p['version']}";
        }
        if (!is_dir("vendor/$name")) {
            $errors[] = "vendor/$name is missing";
        }
    }
    foreach (array_diff_key($installed, $locked) as $name => $_) {
        $errors[] = "vendor/: $name is installed but not locked";
    }

    if ($errors) {
        fail("check failed:\n  " . implode("\n  ", $errors));
    }
    printf("vendor.php: %d packages locked and installed\n", count($locked));
}

match ($argv[1] ?? '') {
    'update' => update(),
    'check' => check(),
    default => fail('usage: php tools/vendor.php update|check'),
};
