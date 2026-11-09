#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$GLOBALS['root'] = $root;
chdir($root);

$patterns_forbidden = array(
    'Dir::Bin::gpg',
    'gpgv-allow-expired',
    'gpgv-wrapper',
    'KEYEXPIRED',
    'GOODSIG',
    'allow-unauthenticated',
    'AllowInsecureRepositories',
    'trusted=yes',
);

/**
 * @return list<string>
 */
function collect_policy_files(): array
{
    $raw = shell_exec('git ls-files -z 2>/dev/null');
    if (!is_string($raw)) {
        return array();
    }
    $out = array();
    foreach (explode("\0", $raw) as $rel) {
        if ($rel === '') {
            continue;
        }
        if (preg_match('#(^|/)Dockerfile(\.|$)|\.dockerfile$#i', $rel)) {
            $out[] = $rel;
        }
        if (preg_match('#apt\.conf#i', $rel)) {
            $out[] = $rel;
        }
        if (strpos($rel, 'docker/') === 0) {
            $out[] = $rel;
        }
    }

    $dockerDir = $GLOBALS['root'] . '/docker';
    if (is_dir($dockerDir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dockerDir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'Dockerfile') {
                $rel = str_replace($GLOBALS['root'] . '/', '', $file->getPathname());
                $out[] = $rel;
            }
        }
    }
    if (is_file($GLOBALS['root'] . '/Dockerfile')) {
        $out[] = 'Dockerfile';
    }

    return array_values(array_unique($out));
}

/**
 * @param list<string> $patterns_forbidden
 */
function check_policy_file(string $file, bool $require_archive_scope, array $patterns_forbidden): bool
{
    if (!is_readable($file)) {
        fwrite(STDERR, "check-apt-policy: missing file {$file}\n");

        return false;
    }
    $text = file_get_contents($file);
    if ($text === false) {
        return false;
    }
    $fail = false;
    foreach ($patterns_forbidden as $pat) {
        if (stripos($text, $pat) !== false) {
            fwrite(STDERR, "check-apt-policy: forbidden pattern in {$file}: {$pat}\n");
            $fail = true;
        }
    }
    if (preg_match('/http:\/\//', $text) === 1) {
        fwrite(STDERR, "check-apt-policy: {$file} must not use http:// apt sources\n");
        $fail = true;
    }
    if (preg_match('/Acquire::Check-Valid-Until[[:space:]]+"false"/', $text) === 1) {
        fwrite(STDERR, "check-apt-policy: unscoped Acquire::Check-Valid-Until in {$file}\n");
        $fail = true;
    }
    if ($require_archive_scope && strpos($text, 'Acquire::Check-Valid-Until::"archive.debian.org" "false"') === false) {
        fwrite(STDERR, "check-apt-policy: {$file} missing archive.debian.org-scoped Check-Valid-Until\n");
        $fail = true;
    }

    return !$fail;
}

$policy_files = collect_policy_files();
if ($policy_files === array()) {
    fwrite(STDERR, "check-apt-policy: no policy files found\n");
    exit(1);
}

foreach ($policy_files as $rel) {
    $require = $rel === 'docker/system-test/Dockerfile';
    if (!check_policy_file($root . '/' . $rel, $require, $patterns_forbidden)) {
        exit(1);
    }
}

$dockerfile = $root . '/docker/system-test/Dockerfile';
$planted = $dockerfile . '.plant';
copy($dockerfile, $planted);
file_put_contents($planted, "\nAcquire::Check-Valid-Until \"false\";\n", FILE_APPEND);
if (check_policy_file($planted, false, $patterns_forbidden)) {
    unlink($planted);
    fwrite(STDERR, "check-apt-policy: in-memory unscoped plant must be rejected\n");
    exit(1);
}
unlink($planted);

fwrite(STDOUT, "check-apt-policy: all Docker and apt policy files pass verification policy.\n");
exit(0);
