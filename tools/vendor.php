<?php
// Manage the committed vendor/ tree (see docs/stack.md).
//
//   php tools/vendor.php update   Resolve composer.json into composer.lock under
//                                 the 7-day cooldown, then install into vendor/.
//                                 Needs the network.
//   php tools/vendor.php check    Verify, offline, that composer.json pins exact
//                                 versions, that composer.lock matches it, that
//                                 vendor/ holds exactly the locked versions, and
//                                 that dependencies.json lists exactly them.
//   php tools/vendor.php manifest Write dependencies.json, the dependency
//                                 manifest tadmor's tools/measure.py reads
//                                 (tadmor's docs/counterpart-metrics.md): every
//                                 locked package, its category, and its
//                                 Packagist maintainers. Needs the network;
//                                 update runs it.
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
    manifest();
    check();
}

/**
 * The category of each locked package: the runtime tree is runtime, and the
 * dev tree, which is PHPUnit's (docs/stack.md, "Testing"), is test.
 *
 * @return array<string, array{version: string, category: string}>
 */
function categorized(): array
{
    $lock = readJson('composer.lock');
    $out = [];
    foreach (['packages' => 'runtime', 'packages-dev' => 'test'] as $section => $category) {
        foreach ($lock[$section] as $p) {
            $out[$p['name']] = ['version' => $p['version'], 'category' => $category];
        }
    }
    ksort($out);
    return $out;
}

/** The accounts Packagist lists as the package's maintainers (per package, not per version). */
function maintainers(string $name): array
{
    $url = "https://packagist.org/packages/$name.json";
    $context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'tadmor-php tools/vendor.php']]);
    $text = @file_get_contents($url, false, $context);
    if ($text === false) {
        fail("cannot read $url");
    }
    $names = array_map(fn ($m) => 'packagist:' . $m['name'], json_decode($text, true)['package']['maintainers'] ?? []);
    sort($names);
    return $names;
}

function manifest(): void
{
    $packages = [];
    $sources = ['runtime' => ['bytes' => 0, 'lines' => 0], 'test' => ['bytes' => 0, 'lines' => 0]];
    $tracked = explode("\n", trim(shell_exec('git ls-files vendor') ?? ''));
    foreach (categorized() as $name => $p) {
        $packages[] = [
            'ecosystem' => 'packagist', 'name' => $name, 'version' => $p['version'], 'category' => $p['category'],
            'identities' => maintainers($name), 'evidence' => "https://packagist.org/packages/$name.json",
        ];
        foreach ($tracked as $file) {
            if (str_starts_with($file, "vendor/$name/")) {
                $sources[$p['category']]['bytes'] += filesize($file);
                if (str_ends_with($file, '.php')) {
                    $sources[$p['category']]['lines'] += count(array_filter(file($file), fn ($l) => trim($l) !== ''));
                }
            }
        }
    }
    usort($packages, fn ($a, $b) => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);
    $doc = [
        'format' => 'tadmor-dependencies/1',
        'generator' => 'tools/vendor.php manifest (tadmor-php)',
        'platform' => 'linux/x64',
        'toolchains' => ['PHP (the operating system\'s build)', 'Composer (the operating system\'s package)'],
        'packages' => $packages,
        'sources' => [
            ['label' => 'Composer vendor/ (runtime)'] + $sources['runtime'],
            ['label' => 'Composer vendor/ (test)'] + $sources['test'],
        ],
    ];
    file_put_contents('dependencies.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("vendor.php: wrote dependencies.json, %d packages\n", count($packages));
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

    if (!is_file('dependencies.json')) {
        $errors[] = 'dependencies.json is missing; run `php tools/vendor.php manifest`';
    } else {
        $listed = [];
        foreach (readJson('dependencies.json')['packages'] as $p) {
            $listed[$p['name']] = ['version' => $p['version'], 'category' => $p['category']];
        }
        ksort($listed);
        if ($listed !== categorized()) {
            $errors[] = 'dependencies.json does not list the locked packages; run `php tools/vendor.php manifest`';
        }
    }

    if ($errors) {
        fail("check failed:\n  " . implode("\n  ", $errors));
    }
    printf("vendor.php: %d packages locked and installed\n", count($locked));
}

match ($argv[1] ?? '') {
    'update' => update(),
    'check' => check(),
    'manifest' => manifest(),
    default => fail('usage: php tools/vendor.php update|check|manifest'),
};
