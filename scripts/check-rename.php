<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/** @var list<string> */
$scan_paths = array(
    $root . '/src',
    $root . '/tests',
    $root . '/ARCHITECTURE.md',
    $root . '/composer.json',
    $root . '/phpunit.xml.dist',
    $root . '/phpstan.neon.dist',
    $root . '/phpstan-baseline.neon',
    $root . '/Makefile',
);

/** @var list<string> */
$forbidden = array(
    'GuzzleHttp\\UriTemplate',
    'namespace GuzzleHttp\\UriTemplate',
    'UriTemplateTest',
    'UriTemplate::',
    'new UriTemplate',
    'expandMatch',
    'expandMatchCallback',
    'parseExpression',
    'isAssoc',
    'decodeReserved',
    'operatorHash',
    'delimsPct',
    'templateProvider',
    'expressionProvider',
    'specComplianceProvider',
    'testExpandsUriTemplates',
    'testParsesExpressions',
    'testAllowsNestedArrayExpansion',
    'testSpecCompliance',
    'parseSpecExamples',
    'final class UriTemplate',
    'class UriTemplate',
);

/** @var array<string, list<string>> */
$allowlist = array();
$allowlist_path = __DIR__ . '/rename-allowlist.txt';
if (is_readable($allowlist_path)) {
    $lines = file($allowlist_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $file = trim($parts[0]);
            $site = trim($parts[1]);
            if (!isset($allowlist[$file])) {
                $allowlist[$file] = array();
            }
            $allowlist[$file][] = $site;
        }
    }
}

$failures = 0;

/**
 * @param list<string> $needles
 */
function file_contains_any(string $path, string $content, array $needles): array
{
    $hits = array();
    foreach ($needles as $needle) {
        if (strpos($content, $needle) !== false) {
            $hits[] = $needle;
        }
    }

    return $hits;
}

function is_studly_snake(string $name): bool
{
    if ($name === '' || !ctype_upper($name[0])) {
        return false;
    }
    if (strpos($name, '_') === false) {
        return preg_match('/^[A-Z][a-z0-9]*$/', $name) === 1;
    }
    $parts = explode('_', $name);
    foreach ($parts as $part) {
        if ($part === '' || !ctype_upper($part[0])) {
            return false;
        }
        if (preg_match('/[A-Z]/', substr($part, 1)) === 1) {
            return false;
        }
    }

    return true;
}

function is_repo_snake_identifier(string $name): bool
{
    if ($name === '' || $name[0] === '_') {
        return $name !== '' && $name[0] === '_' && strpos($name, '__') === 0;
    }
    if (preg_match('/[A-Z]/', $name) === 1) {
        return false;
    }

    return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $name);
}

function has_camel_hump(string $name): bool
{
    if ($name === '' || $name[0] === '_') {
        return false;
    }
    if (preg_match('/^[A-Z0-9_]+$/', $name) === 1) {
        return false;
    }

    return (bool) preg_match('/[a-z][A-Z]/', $name);
}

/**
 * @param array<string, list<string>> $allowlist
 */
function is_allowlisted(string $rel_file, string $method, array $allowlist): bool
{
    if (!isset($allowlist[$rel_file])) {
        return false;
    }
    foreach ($allowlist[$rel_file] as $site) {
        if (strpos($site, '::') === false) {
            continue;
        }
        list($fq, $meth) = explode('::', $site, 2);
        if ($meth !== $method) {
            continue;
        }
        if (method_exists($fq, $meth)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, list<string>> $allowlist
 */
function scan_php_file(string $abs_path, string $rel_path, array $allowlist, int &$failures): void
{
    $content = file_get_contents($abs_path);
    if ($content === false) {
        return;
    }

    $namespace = '';
    if (preg_match('/^\s*namespace\s+([^;]+);/m', $content, $m)) {
        $namespace = trim($m[1]);
    }

    if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $classes)) {
        foreach ($classes[1] as $class) {
            if (strpos($namespace, 'Guzzle_Http\\') === 0 && !is_studly_snake($class)) {
                fwrite(STDERR, "check-rename: repo class not Studly_Snake_Case: {$class} in {$rel_path}\n");
                $failures++;
            }
        }
    }

    if (preg_match_all('/^\s*(?:public|protected|private)?\s*(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $content, $fns, PREG_OFFSET_CAPTURE)) {
        foreach ($fns[1] as $pair) {
            $fn = $pair[0];
            if (strpos($fn, '__') === 0) {
                continue;
            }
            if (strpos($namespace, 'Guzzle_Http\\') === 0 && !is_repo_snake_identifier($fn)) {
                fwrite(STDERR, "check-rename: repo method not snake_case: {$fn} in {$rel_path}\n");
                $failures++;
            }
        }
    }

    if (preg_match_all('/@dataProvider\s+([A-Za-z_][A-Za-z0-9_]*)/', $content, $providers)) {
        foreach ($providers[1] as $provider) {
            if (has_camel_hump($provider)) {
                fwrite(STDERR, "check-rename: camelCase @dataProvider {$provider} in {$rel_path}\n");
                $failures++;
            }
        }
    }

    $tokens = token_get_all($content);
    $class = '';
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok)) {
            continue;
        }
        if ($tok[0] === T_CLASS) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $class = $tokens[$j][1];
                    break;
                }
            }
        }
        if ($tok[0] === T_STRING && $tok[1] === 'parent' && isset($tokens[$i + 1]) && $tokens[$i + 1] === '::') {
            if (isset($tokens[$i + 2]) && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_STRING) {
                $method = $tokens[$i + 2][1];
                if (has_camel_hump($method) && !is_allowlisted($rel_path, $method, $allowlist)) {
                    fwrite(STDERR, "check-rename: unallowlisted parent call {$method} in {$rel_path}\n");
                    $failures++;
                }
            }
        }
        if ($tok[0] === T_OBJECT_OPERATOR && isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING) {
            $method = $tokens[$i + 1][1];
            if (!has_camel_hump($method)) {
                continue;
            }
            if (is_allowlisted($rel_path, $method, $allowlist)) {
                continue;
            }
            fwrite(STDERR, "check-rename: unallowlisted camelCase call {$method} in {$rel_path}\n");
            $failures++;
        }
    }
}

foreach ($scan_paths as $path) {
    if (is_file($path)) {
        $rel = str_replace($root . '/', '', $path);
        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }
        foreach (file_contains_any($rel, $content, $forbidden) as $hit) {
            fwrite(STDERR, "check-rename: forbidden identifier {$hit} in {$rel}\n");
            $failures++;
        }
        if (substr($path, -4) === '.php') {
            scan_php_file($path, $rel, $allowlist, $failures);
        }
        continue;
    }
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $file = $fileInfo->getPathname();
        $rel = str_replace($root . '/', '', $file);
        if (substr($file, -4) !== '.php' && substr($file, -3) !== '.md') {
            continue;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            continue;
        }
        foreach (file_contains_any($rel, $content, $forbidden) as $hit) {
            fwrite(STDERR, "check-rename: forbidden identifier {$hit} in {$rel}\n");
            $failures++;
        }
        if (substr($file, -4) === '.php') {
            scan_php_file($file, $rel, $allowlist, $failures);
        }
    }
}

$good = <<<'PHP'
<?php
namespace Guzzle_Http\Uri_Template\Tests;
class Uri_Template_Test extends \PHPUnit\Framework\TestCase {
    public function test_ok() { $this->assertSame(1, 1); }
}
PHP;
$bad = <<<'PHP'
<?php
namespace Guzzle_Http\Uri_Template\Tests;
class UriTemplateTest extends \PHPUnit\Framework\TestCase {
    public function testBad() {}
}
PHP;
if (strpos($good, 'UriTemplateTest') !== false) {
    $failures++;
}
$bad_hits = file_contains_any('demo-bad.php', $bad, array('UriTemplateTest', 'testBad'));
if ($bad_hits === array()) {
    fwrite(STDERR, "check-rename: demo must detect old class/method names.\n");
    $failures++;
}

$outside = <<<'PHP'
<?php
namespace Guzzle_Http\Uri_Template\Tests;
class Uri_Template_Test extends \PHPUnit\Framework\TestCase {
    public function test_x() { $this->assertTrue(true); }
}
PHP;
$tmp = sys_get_temp_dir() . '/uri-rename-demo-' . getmypid() . '.php';
file_put_contents($tmp, $outside);
$demo_fail = 0;
scan_php_file($tmp, 'demo/outside.php', $allowlist, $demo_fail);
unlink($tmp);
if ($demo_fail === 0) {
    fwrite(STDERR, "check-rename: outside-site demo must reject assertTrue without allowlist.\n");
    $failures++;
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "check-rename: snake_case convention holds; no forbidden upstream names remain.\n");
exit(0);
