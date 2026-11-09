<?php

declare(strict_types=1);

/**
 * Verify cluster package pins and Packagist gap guard for phpunit/phpunit.
 */

/** @var array<string, array{alias: string, reference: string}> */
$expected = array(
);

$forbidden_org_refs = array(
    'a37d66fabf01af0eedabffe271b70dbe71723001',
    '698cbbc1e75a1ef1968f9cbfec89be056b288d0f',
);

$installedPhpPath = dirname(__DIR__) . '/vendor/composer/installed.php';
$installedJsonPath = dirname(__DIR__) . '/vendor/composer/installed.json';

if (!is_readable($installedPhpPath)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.php not found; run composer update first.\n");
    exit(1);
}
if (!is_readable($installedJsonPath)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.json not found; run composer update first.\n");
    exit(1);
}

/** @var array{versions?: array<string, array<string, mixed>>} $installedPhp */
$installedPhp = require $installedPhpPath;
$phpVersions = $installedPhp['versions'] ?? array();

$jsonRaw = file_get_contents($installedJsonPath);
if ($jsonRaw === false) {
    fwrite(STDERR, "check-cluster-pins: could not read installed.json.\n");
    exit(1);
}

/** @var array{packages?: list<array<string, mixed>>, 'packages-dev'?: list<array<string, mixed>>} $jsonData */
$jsonData = json_decode($jsonRaw, true);
if (!is_array($jsonData)) {
    fwrite(STDERR, "check-cluster-pins: invalid installed.json.\n");
    exit(1);
}

/** @var array<string, array{reference: string, aliases: list<string>, package: array<string, mixed>}> $jsonByName */
$jsonByName = array();
foreach (array_merge($jsonData['packages'] ?? array(), $jsonData['packages-dev'] ?? array()) as $pkg) {
    if (!isset($pkg['name'], $pkg['source']['reference']) || !is_string($pkg['name'])) {
        continue;
    }
    $jsonByName[$pkg['name']] = array(
        'reference' => strtolower((string) $pkg['source']['reference']),
        'aliases' => isset($pkg['aliases']) && is_array($pkg['aliases']) ? $pkg['aliases'] : array(),
        'package' => $pkg,
    );
}

/**
 * @param array{reference?: string, aliases?: list<string>} $entry
 */
function check_php_pin(string $name, string $wantRef, string $wantAlias, array $entry): bool
{
    $aliases = $entry['aliases'] ?? array();
    if (!in_array($wantAlias, $aliases, true)) {
        return false;
    }
    $reference = $entry['reference'] ?? null;
    if (!is_string($reference) || $reference === '') {
        return false;
    }

    return hash_equals(strtolower($wantRef), strtolower($reference));
}

function check_json_pin(string $name, string $wantRef, array $jsonSide): bool
{
    if (!isset($jsonSide[$name])) {
        return false;
    }

    return hash_equals(strtolower($wantRef), $jsonSide[$name]['reference']);
}

/**
 * @param array<string, array{reference?: string, aliases?: list<string>}> $phpSide
 */
function validate_org_expected(array $expected, array $phpSide, array $jsonByName): int
{
    $failed = 0;
    foreach ($expected as $name => $want) {
        if (!isset($phpSide[$name])) {
            fwrite(STDERR, "check-cluster-pins [installed.php]: {$name} not installed.\n");
            $failed++;
            continue;
        }
        $phpEntry = array(
            'reference' => isset($phpSide[$name]['reference']) ? $phpSide[$name]['reference'] : '',
            'aliases' => $phpSide[$name]['aliases'],
        );
        if (!check_php_pin($name, $want['reference'], $want['alias'], $phpEntry)) {
            fwrite(STDERR, "check-cluster-pins [installed.php]: {$name} mismatch.\n");
            $failed++;
        }
        if (!check_json_pin($name, $want['reference'], $jsonByName)) {
            fwrite(STDERR, "check-cluster-pins [installed.json]: {$name} mismatch.\n");
            $failed++;
        }
    }

    return $failed;
}

/** @var array<string, array{reference: string, aliases: list<string>}> $phpSide */
$phpSide = array();
foreach ($phpVersions as $name => $meta) {
    if ($name === '' || $name[0] === '/') {
        continue;
    }
    $phpSide[$name] = array(
        'reference' => isset($meta['reference']) ? strtolower((string) $meta['reference']) : '',
        'aliases' => isset($meta['aliases']) && is_array($meta['aliases']) ? $meta['aliases'] : array(),
    );
}

$failed = validate_org_expected($expected, $phpSide, $jsonByName);

if (!isset($phpVersions['phpunit/phpunit'])) {
    fwrite(STDERR, "check-cluster-pins: phpunit/phpunit gap guard failed: package not installed.\n");
    $failed++;
} else {
    $phpUnit = $phpVersions['phpunit/phpunit'];
    $version = isset($phpUnit['pretty_version']) ? (string) $phpUnit['pretty_version'] : '';
    if ($version === '') {
        $version = isset($phpUnit['version']) ? (string) $phpUnit['version'] : '';
    }
    $versionOk = false;
    if ($version !== '' && version_compare($version, '8.5.52', '>=') && version_compare($version, '9.0.0', '<')) {
        $versionOk = true;
    }
    if ($version !== '' && version_compare($version, '9.6.33', '>=') && version_compare($version, '10.0.0', '<')) {
        $versionOk = true;
    }
    if (!$versionOk) {
        fwrite(STDERR, "check-cluster-pins: phpunit/phpunit must be Packagist ^8.5.52 || ^9.6.33 (got {$version}).\n");
        $failed++;
    }
    if (PHP_VERSION_ID < 70300 && $version !== '' && version_compare($version, '9.0.0', '>=')) {
        fwrite(STDERR, "check-cluster-pins: phpunit/phpunit on PHP 7.2 floor must stay in 8.5.x (got {$version}).\n");
        $failed++;
    }
    $ref = isset($phpUnit['reference']) ? strtolower((string) $phpUnit['reference']) : '';
    if (in_array($ref, $forbidden_org_refs, true)) {
        fwrite(STDERR, "check-cluster-pins: phpunit/phpunit must not resolve to Training-Datasmith index tip.\n");
        $failed++;
    }
    $aliases = $phpUnit['aliases'] ?? array();
    if (is_array($aliases) && $aliases !== array()) {
        fwrite(STDERR, "check-cluster-pins: phpunit/phpunit must not use composer inline aliases.\n");
        $failed++;
    }
    if (isset($jsonByName['phpunit/phpunit'])) {
        $pkg = $jsonByName['phpunit/phpunit']['package'];
        $url = (string) ($pkg['source']['url'] ?? '');
        if ($url !== '' && stripos($url, 'Training-Datasmith/phpunit') !== false) {
            fwrite(STDERR, "check-cluster-pins: phpunit/phpunit source must be Packagist/sebastianbergmann, not org fork.\n");
            $failed++;
        }
        if ($url !== '' && stripos($url, 'sebastianbergmann/phpunit') === false) {
            fwrite(STDERR, "check-cluster-pins: phpunit/phpunit unexpected source URL.\n");
            $failed++;
        }
        $jsonRef = $jsonByName['phpunit/phpunit']['reference'];
        if ($ref !== '' && !hash_equals($ref, $jsonRef)) {
            fwrite(STDERR, "check-cluster-pins: phpunit/phpunit installed.php reference != installed.json source.reference.\n");
            $failed++;
        }
    }
}

foreach ($jsonByName as $name => $meta) {
    if ($name === 'guzzlehttp/uri-template') {
        continue;
    }
    $pkg = $meta['package'];
    foreach (array('source', 'dist') as $block) {
        if (!isset($pkg[$block]['url']) || !is_string($pkg[$block]['url'])) {
            continue;
        }
        $url = $pkg[$block]['url'];
        if (stripos($url, 'Training-Datasmith') !== false) {
            fwrite(STDERR, "check-cluster-pins: {$name} must not resolve from Training-Datasmith ({$url}).\n");
            $failed++;
        }
    }
}

$demoExpected = array(
    'demo/pin' => array(
        'alias' => '1.2.3',
        'reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ),
);

$demoPhp = $phpSide;
$demoPhp['demo/pin'] = array(
    'reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'aliases' => array('1.2.3'),
);
$demoJson = $jsonByName;
$demoJson['demo/pin'] = array(
    'reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'aliases' => array('1.2.3'),
    'package' => array(
        'name' => 'demo/pin',
        'source' => array('reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
    ),
);

if (validate_org_expected($demoExpected, $demoPhp, $demoJson) !== 0) {
    fwrite(STDERR, "check-cluster-pins: demo control failed.\n");
    $failed++;
}

$wrongPhp = $demoPhp;
$wrongPhp['demo/pin']['reference'] = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$phpEntry = array(
    'reference' => $wrongPhp['demo/pin']['reference'],
    'aliases' => $wrongPhp['demo/pin']['aliases'],
);
$phpShouldFail = !check_php_pin('demo/pin', $demoExpected['demo/pin']['reference'], $demoExpected['demo/pin']['alias'], $phpEntry);
$jsonShouldPass = check_json_pin('demo/pin', $demoExpected['demo/pin']['reference'], $demoJson);
if (!$phpShouldFail || !$jsonShouldPass) {
    fwrite(STDERR, "check-cluster-pins: installed.php wrong-SHA demo invalid.\n");
    $failed++;
}

$wrongJson = $demoJson;
$wrongJson['demo/pin']['reference'] = 'cccccccccccccccccccccccccccccccccccccccc';
$jsonShouldFail = !check_json_pin('demo/pin', $demoExpected['demo/pin']['reference'], $wrongJson);
$goodPhpEntry = array(
    'reference' => $demoPhp['demo/pin']['reference'],
    'aliases' => $demoPhp['demo/pin']['aliases'],
);
$phpShouldPass = check_php_pin('demo/pin', $demoExpected['demo/pin']['reference'], $demoExpected['demo/pin']['alias'], $goodPhpEntry);
if (!$jsonShouldFail || !$phpShouldPass) {
    fwrite(STDERR, "check-cluster-pins: installed.json wrong-SHA demo invalid.\n");
    $failed++;
}

if ($failed > 0) {
    exit(1);
}

fwrite(STDOUT, 'check-cluster-pins: all cluster packages match expected SHAs and aliases.' . "\n");
exit(0);
