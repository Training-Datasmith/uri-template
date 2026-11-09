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

/**
 * @return list<string>
 */
function collect_json_references(array $package): array
{
    $refs = array();
    foreach (array('source', 'dist') as $block) {
        if (!isset($package[$block]) || !is_array($package[$block])) {
            continue;
        }
        if (isset($package[$block]['reference']) && is_string($package[$block]['reference'])) {
            $refs[] = strtolower($package[$block]['reference']);
        }
    }

    return $refs;
}

/**
 * @param list<string> $forbidden_org_refs
 *
 * @return list<string>
 */
function collect_json_urls(array $package): array
{
    $urls = array();
    foreach (array('source', 'dist') as $block) {
        if (!isset($package[$block]) || !is_array($package[$block])) {
            continue;
        }
        if (isset($package[$block]['url']) && is_string($package[$block]['url'])) {
            $urls[] = $package[$block]['url'];
        }
    }

    return $urls;
}

/** @var array<string, array{reference: string, aliases: list<string>, package: array<string, mixed>}> $jsonByName */
$jsonByName = array();
foreach (array_merge($jsonData['packages'] ?? array(), $jsonData['packages-dev'] ?? array()) as $pkg) {
    if (!isset($pkg['name']) || !is_string($pkg['name'])) {
        continue;
    }
    $refs = collect_json_references($pkg);
  $sourceRef = isset($pkg['source']['reference']) && is_string($pkg['source']['reference'])
        ? strtolower($pkg['source']['reference'])
        : ($refs[0] ?? '');
    $jsonByName[$pkg['name']] = array(
        'reference' => $sourceRef,
        'aliases' => isset($pkg['aliases']) && is_array($pkg['aliases']) ? $pkg['aliases'] : array(),
        'package' => $pkg,
    );
}

/**
 * @param array{reference?: string, aliases?: list<string>, pretty_version?: string, version?: string} $phpUnit
 * @param array<string, mixed>|null $jsonPkg
 * @param list<string> $forbidden_org_refs
 */
function validate_gap_phpunit(array $phpUnit, ?array $jsonPkg, array $forbidden_org_refs): ?string
{
    if ($jsonPkg === null) {
        return 'check-cluster-pins [installed.json]: phpunit/phpunit is not installed.';
    }

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
        return "check-cluster-pins: phpunit/phpunit must be Packagist ^8.5.52 || ^9.6.33 (got {$version}).";
    }
    if (PHP_VERSION_ID < 70300 && $version !== '' && version_compare($version, '9.0.0', '>=')) {
        return "check-cluster-pins: phpunit/phpunit on PHP 7.2 floor must stay in 8.5.x (got {$version}).";
    }

    $ref = isset($phpUnit['reference']) ? strtolower((string) $phpUnit['reference']) : '';
    if ($ref === '' || strlen($ref) !== 40 || !ctype_xdigit($ref)) {
        return 'check-cluster-pins [installed.php]: phpunit/phpunit has no valid reference.';
    }
    if (in_array($ref, $forbidden_org_refs, true)) {
        return 'check-cluster-pins [installed.php]: phpunit/phpunit must not resolve to Training-Datasmith index tip.';
    }

    $aliases = $phpUnit['aliases'] ?? array();
    if (is_array($aliases) && $aliases !== array()) {
        return 'check-cluster-pins [installed.php]: phpunit/phpunit must not use composer inline aliases.';
    }

    $jsonVersion = isset($jsonPkg['version']) ? (string) $jsonPkg['version'] : '';
    if ($jsonVersion === '') {
        return 'check-cluster-pins [installed.json]: phpunit/phpunit has no version.';
    }
    if ($version !== '' && $jsonVersion !== $version) {
        return "check-cluster-pins: phpunit/phpunit version mismatch installed.php={$version} installed.json={$jsonVersion}.";
    }

    $jsonSourceRef = isset($jsonPkg['source']['reference']) && is_string($jsonPkg['source']['reference'])
        ? strtolower($jsonPkg['source']['reference'])
        : '';
    $jsonDistRef = isset($jsonPkg['dist']['reference']) && is_string($jsonPkg['dist']['reference'])
        ? strtolower($jsonPkg['dist']['reference'])
        : '';
    if ($jsonSourceRef === '' || strlen($jsonSourceRef) !== 40) {
        return 'check-cluster-pins [installed.json]: phpunit/phpunit has no valid source.reference.';
    }
    if (!hash_equals($ref, $jsonSourceRef)) {
        return 'check-cluster-pins: phpunit/phpunit installed.php reference != installed.json source.reference.';
    }
    if ($jsonDistRef !== '' && !hash_equals($ref, $jsonDistRef)) {
        return 'check-cluster-pins [installed.json]: phpunit/phpunit dist.reference != source.reference.';
    }

    foreach (collect_json_references($jsonPkg) as $jsonRef) {
        if (in_array($jsonRef, $forbidden_org_refs, true)) {
            return "check-cluster-pins [installed.json]: phpunit/phpunit must not match Training-Datasmith index SHA {$jsonRef}.";
        }
    }

    foreach (collect_json_urls($jsonPkg) as $url) {
        if (stripos($url, 'Training-Datasmith') !== false) {
            return "check-cluster-pins [installed.json]: phpunit/phpunit must not come from Training-Datasmith ({$url}).";
        }
    }

    $sourceUrl = (string) ($jsonPkg['source']['url'] ?? '');
    if ($sourceUrl !== '' && stripos($sourceUrl, 'sebastianbergmann/phpunit') === false) {
        return 'check-cluster-pins [installed.json]: phpunit/phpunit unexpected source URL.';
    }

    return null;
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
    $jsonPkg = isset($jsonByName['phpunit/phpunit']) ? $jsonByName['phpunit/phpunit']['package'] : null;
    $gapError = validate_gap_phpunit($phpVersions['phpunit/phpunit'], $jsonPkg, $forbidden_org_refs);
    if ($gapError !== null) {
        fwrite(STDERR, $gapError . "\n");
        $failed++;
    }
}

foreach ($jsonByName as $name => $meta) {
    if ($name === 'guzzlehttp/uri-template') {
        continue;
    }
    $pkg = $meta['package'];
    foreach (collect_json_urls($pkg) as $url) {
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

$goodPhpUnit = array(
    'pretty_version' => '8.5.56',
    'version' => '8.5.56.0',
    'reference' => '951b085c397146e9811b736f21eea53109aefcc0',
    'aliases' => array(),
);
$goodJsonUnit = array(
    'name' => 'phpunit/phpunit',
    'version' => '8.5.56',
    'source' => array(
        'reference' => '951b085c397146e9811b736f21eea53109aefcc0',
        'url' => 'https://github.com/sebastianbergmann/phpunit.git',
    ),
    'dist' => array(
        'reference' => '951b085c397146e9811b736f21eea53109aefcc0',
        'url' => 'https://api.github.com/repos/sebastianbergmann/phpunit/zipball/951b085c397146e9811b736f21eea53109aefcc0',
    ),
);
if (validate_gap_phpunit($goodPhpUnit, $goodJsonUnit, $forbidden_org_refs) !== null) {
    fwrite(STDERR, "check-cluster-pins: gap demo control failed.\n");
    $failed++;
}

$badPhpRef = $goodPhpUnit;
$badPhpRef['reference'] = $forbidden_org_refs[0];
if (validate_gap_phpunit($badPhpRef, $goodJsonUnit, $forbidden_org_refs) === null) {
    fwrite(STDERR, "check-cluster-pins: gap installed.php wrong-SHA demo must fail.\n");
    $failed++;
}
$stillGoodJson = validate_gap_phpunit($goodPhpUnit, $goodJsonUnit, $forbidden_org_refs);
if ($stillGoodJson !== null) {
    fwrite(STDERR, "check-cluster-pins: gap installed.php wrong-SHA demo broke json side.\n");
    $failed++;
}

$badJsonRef = $goodJsonUnit;
$badJsonRef['source']['reference'] = $forbidden_org_refs[1];
$badJsonRef['dist']['reference'] = $forbidden_org_refs[1];
if (validate_gap_phpunit($goodPhpUnit, $badJsonRef, $forbidden_org_refs) === null) {
    fwrite(STDERR, "check-cluster-pins: gap installed.json wrong-SHA demo must fail.\n");
    $failed++;
}

$orgUrlJson = $goodJsonUnit;
$orgUrlJson['source']['url'] = 'https://github.com/Training-Datasmith/phpunit.git';
$orgUrlJson['dist']['url'] = 'https://github.com/Training-Datasmith/phpunit.git';
if (validate_gap_phpunit($goodPhpUnit, $orgUrlJson, $forbidden_org_refs) === null) {
    fwrite(STDERR, "check-cluster-pins: gap installed.json org URL demo must fail.\n");
    $failed++;
}

$missingJsonGap = validate_gap_phpunit($goodPhpUnit, null, $forbidden_org_refs);
if ($missingJsonGap === null) {
    fwrite(STDERR, "check-cluster-pins: missing installed.json entry demo must fail.\n");
    $failed++;
}

if ($failed > 0) {
    exit(1);
}

fwrite(STDOUT, 'check-cluster-pins: all cluster packages match expected SHAs and aliases.' . "\n");
exit(0);
