<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/check-phpunit-gate.php <phpunit-output.log>\n");
    exit(2);
}

$root = dirname(__DIR__);
$logPath = $argv[1];
$baselinePath = $root . '/scripts/phpunit-baseline.txt';
$allowlistPath = $root . '/scripts/allowed-skipped-tests.txt';

if (!is_readable($logPath)) {
    fwrite(STDERR, "Cannot read {$logPath}\n");
    exit(2);
}
if (!is_readable($baselinePath)) {
    fwrite(STDERR, "Missing baseline file\n");
    exit(2);
}

$expectedLine = trim((string) file_get_contents($baselinePath));
if ($expectedLine === '') {
    fwrite(STDERR, "Empty phpunit baseline line\n");
    exit(2);
}

/** @var array<string, string> $allowedSkips */
$allowedSkips = array();
if (is_readable($allowlistPath)) {
    foreach (file($allowlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('|', $line, 2);
        if (count($parts) === 2) {
            $allowedSkips[trim($parts[0])] = trim($parts[1]);
        }
    }
}

/**
 * @param array<string, string> $allowedSkips
 */
function validate_phpunit_output(string $text, string $expectedLine, array $allowedSkips): ?string
{
    $stripped = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $text);
    if (!is_string($stripped)) {
        $stripped = $text;
    }

    foreach (preg_split('/\R/', $stripped) as $line) {
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(PHP )?(Warning|Deprecated|Notice):/', $line)) {
            return 'PHPUnit output contains PHP noise';
        }
        if (preg_match('/^OK, but incomplete, skipped, or risky tests!/', $line)) {
            return 'PHPUnit reported incomplete, skipped, or risky tests';
        }
    }

    if (!preg_match('/^' . preg_quote($expectedLine, '/') . '$/m', $stripped)) {
        return 'PHPUnit output missing anchored baseline OK line: ' . $expectedLine;
    }

  /** @var list<array{id: string, reason: string}> $skipped */
    $skipped = array();
    if (preg_match('/There were (\d+) skipped tests:/', $stripped, $countMatch)) {
        $count = (int) $countMatch[1];
        if ($count !== count($allowedSkips)) {
            return 'Unexpected skipped test count: ' . $count;
        }
        if (preg_match('/There were \d+ skipped tests:\R(.*)\R(?:OK, but|\Z)/s', $stripped, $sectionMatch)) {
            $section = trim($sectionMatch[1]);
            foreach (preg_split('/\R\R/', $section) as $block) {
                $lines = preg_split('/\R/', trim($block));
                if ($lines === false || count($lines) < 2) {
                    continue;
                }
                if (!preg_match('/^\d+\) (.+)$/', $lines[0], $idMatch)) {
                    continue;
                }
                $skipped[] = array('id' => trim($idMatch[1]), 'reason' => trim($lines[1]));
            }
        }
        if (count($skipped) !== count($allowedSkips)) {
            return 'Could not parse skipped tests from PHPUnit output';
        }
        foreach ($skipped as $skip) {
            if (!isset($allowedSkips[$skip['id']])) {
                return 'Unexpected skipped test: ' . $skip['id'];
            }
            if ($allowedSkips[$skip['id']] !== $skip['reason']) {
                return 'Skip reason mismatch for ' . $skip['id'];
            }
        }
        foreach (array_keys($allowedSkips) as $requiredId) {
            $found = false;
            foreach ($skipped as $skip) {
                if ($skip['id'] === $requiredId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return 'Allowlisted skip not present in run: ' . $requiredId;
            }
        }
    } elseif (count($allowedSkips) > 0) {
        return 'PHPUnit output missing skipped tests required by allowlist';
    }

    if (preg_match('/There were (\d+) skipped tests:/', $stripped, $unexpectedSkip) && (int) $unexpectedSkip[1] > 0) {
        return 'PHPUnit output contains skips not covered by allowlist';
    }

    return null;
}

$log = file_get_contents($logPath);
if ($log === false) {
    fwrite(STDERR, "Failed to read log\n");
    exit(2);
}

$err = validate_phpunit_output($log, $expectedLine, $allowedSkips);
if ($err !== null) {
    fwrite(STDERR, $err . "\n");
    exit(1);
}

$unlisted_skip_log = "OK (1 test, 1 assertion)\n\nThere were 1 skipped tests:\n\n1) Guzzle_Http\\Uri_Template\\Tests\\Uri_Template_Test::test_spec_compliance\n   reason\n";
if (validate_phpunit_output($unlisted_skip_log, 'OK (1 test, 1 assertion)', $allowedSkips) === null) {
    fwrite(STDERR, "check-phpunit-gate: unlisted skip demo must fail\n");
    exit(1);
}

$missing_allow = $allowedSkips;
$missing_allow['Guzzle_Http\\Uri_Template\\Tests\\Uri_Template_Test::test_missing'] = 'missing';
$clean = $expectedLine . "\n";
if (validate_phpunit_output($clean, $expectedLine, $missing_allow) === null) {
    fwrite(STDERR, "check-phpunit-gate: missing listed skip demo must fail\n");
    exit(1);
}

fwrite(STDOUT, "PHPUnit gate passed: {$expectedLine}\n");
exit(0);
