<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

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
 * @param array<string, list<string>> $allowlist
 */
function verify_allowlist_entries(array $allowlist): ?string
{
    foreach ($allowlist as $file => $sites) {
        foreach ($sites as $site) {
            if (strpos($site, '::') === false) {
                return "check-rename: invalid allowlist site {$site} for {$file}";
            }
            list($class, $method) = explode('::', $site, 2);
            if ($method === '' || !method_exists($class, $method)) {
                return "check-rename: allowlist site {$site} failed method_exists";
            }
        }
    }

    return null;
}

$allowlistError = verify_allowlist_entries($allowlist);
if ($allowlistError !== null) {
    fwrite(STDERR, $allowlistError . "\n");
    exit(1);
}

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

function repo_owned_bad_casing(string $name): bool
{
    if ($name === '' || $name[0] === '_') {
        return false;
    }
    if (preg_match('/_[A-Z]/', $name) === 1) {
        return true;
    }
    if (preg_match('/[a-z][A-Z]/', $name) === 1) {
        return true;
    }

    return false;
}

function is_repo_snake_identifier(string $name): bool
{
    return !repo_owned_bad_casing($name) && (bool) preg_match('/^[a-z][a-z0-9_]*$/', $name);
}

/**
 * @param array<string, list<string>> $allowlist
 */
function is_allowlisted_site(string $rel_file, string $fq_class, string $method, array $allowlist): bool
{
    if (!isset($allowlist[$rel_file])) {
        return false;
    }
    $key = $fq_class . '::' . $method;

    return in_array($key, $allowlist[$rel_file], true) && method_exists($fq_class, $method);
}

/**
 * @param array<string, list<string>> $allowlist
 */
function scan_docblocks(string $content, string $rel_path, bool $repo_owned, array $allowlist, int &$failures): void
{
    if (!preg_match_all('/\/\*\*.*?\*\/|\/\*.*?\*\/|\/\/.*$/ms', $content, $blocks)) {
        return;
    }
    foreach ($blocks[0] as $block) {
        if (preg_match_all('/@dataProvider\s+([A-Za-z_][A-Za-z0-9_]*)/', $block, $providers)) {
            foreach ($providers[1] as $provider) {
                if ($repo_owned && repo_owned_bad_casing($provider)) {
                    fwrite(STDERR, "check-rename: bad @dataProvider {$provider} in docblock at {$rel_path}\n");
                    $failures++;
                }
            }
        }
        if (preg_match_all('/@([a-zA-Z]+)\s+([A-Za-z_][A-Za-z0-9_]*)/', $block, $tags, PREG_SET_ORDER)) {
            foreach ($tags as $tag) {
                if ($tag[1] === 'dataProvider') {
                    continue;
                }
                if ($repo_owned && repo_owned_bad_casing($tag[2])) {
                    fwrite(STDERR, "check-rename: bad @{$tag[1]} {$tag[2]} in docblock at {$rel_path}\n");
                    $failures++;
                }
            }
        }
    }

    $tokens = token_get_all($content);
    foreach ($tokens as $tok) {
        if (!is_array($tok)) {
            continue;
        }
        if ($tok[0] !== T_DOC_COMMENT && $tok[0] !== T_COMMENT) {
            continue;
        }
        $text = $tok[1];
        if (preg_match_all('/@dataProvider\s+([A-Za-z_][A-Za-z0-9_]*)/', $text, $providers)) {
            foreach ($providers[1] as $provider) {
                if ($repo_owned && repo_owned_bad_casing($provider)) {
                    fwrite(STDERR, "check-rename: bad @dataProvider {$provider} in {$rel_path}\n");
                    $failures++;
                }
            }
        }
    }
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
    $repo_owned = strpos($namespace, 'Guzzle_Http\\') === 0;

    if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $classes)) {
        foreach ($classes[1] as $class) {
            if ($repo_owned && !is_studly_snake($class)) {
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
            if ($repo_owned && repo_owned_bad_casing($fn)) {
                fwrite(STDERR, "check-rename: repo method not snake_case: {$fn} in {$rel_path}\n");
                $failures++;
            }
        }
    }

    scan_docblocks($content, $rel_path, $repo_owned, $allowlist, $failures);

    $tokens = token_get_all($content);
    $class = '';
    $uses = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok)) {
            continue;
        }

        if ($tok[0] === T_NAMESPACE) {
            $namespace = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_STRING, T_NS_SEPARATOR), true)) {
                    $namespace .= $tokens[$j][1];
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                break;
            }
            $repo_owned = strpos($namespace, 'Guzzle_Http\\') === 0;
        }

        if ($tok[0] === T_USE) {
            $import = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_STRING, T_NS_SEPARATOR), true)) {
                    $import .= $tokens[$j][1];
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_AS) {
                    break;
                }
                if ($import !== '') {
                    $parts = explode('\\', $import);
                    $uses[end($parts)] = $import;
                }
                break;
            }
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
                if (repo_owned_bad_casing($method)
                    && !is_allowlisted_site($rel_path, 'PHPUnit\\Framework\\TestCase', $method, $allowlist)) {
                    fwrite(STDERR, "check-rename: unallowlisted parent::{$method} in {$rel_path}\n");
                    $failures++;
                }
            }
        }

        if ($tok[0] === T_STRING && $tok[1] === 'self' && isset($tokens[$i + 1]) && $tokens[$i + 1] === '::') {
            if (isset($tokens[$i + 2]) && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_STRING) {
                $method = $tokens[$i + 2][1];
                $receiver = 'PHPUnit\\Framework\\TestCase';
                if ($repo_owned && repo_owned_bad_casing($method)
                    && !is_allowlisted_site($rel_path, $receiver, $method, $allowlist)) {
                    fwrite(STDERR, "check-rename: unallowlisted self::{$method} in {$rel_path}\n");
                    $failures++;
                }
            }
        }

        if ($tok[0] === T_STRING && isset($tokens[$i + 1]) && $tokens[$i + 1] === '::'
            && isset($tokens[$i + 2]) && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_STRING
            && $tok[1] !== 'self' && $tok[1] !== 'parent') {
            $staticClass = $tok[1];
            $staticMethod = $tokens[$i + 2][1];
            if (isset($uses[$staticClass])) {
                $staticClass = $uses[$staticClass];
            } elseif (strpos($staticClass, '\\') === false && $namespace !== '') {
                $staticClass = $namespace . '\\' . $staticClass;
            }
            if (repo_owned_bad_casing($staticMethod)
                && !is_allowlisted_site($rel_path, $staticClass, $staticMethod, $allowlist)) {
                fwrite(STDERR, "check-rename: unallowlisted {$staticClass}::{$staticMethod} in {$rel_path}\n");
                $failures++;
            }
        }

        if ($tok[0] === T_OBJECT_OPERATOR && isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING) {
            $method = $tokens[$i + 1][1];
            if (!repo_owned_bad_casing($method)) {
                continue;
            }
            $receiver = 'PHPUnit\\Framework\\TestCase';
            if (!is_allowlisted_site($rel_path, $receiver, $method, $allowlist)
                && !is_allowlisted_site($rel_path, 'ReflectionClass', $method, $allowlist)
                && !is_allowlisted_site($rel_path, 'ReflectionMethod', $method, $allowlist)) {
                fwrite(STDERR, "check-rename: unallowlisted camelCase call {$method} in {$rel_path}\n");
                $failures++;
            }
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

$demo_fail = 0;
$provider_demo = <<<'PHP'
<?php
namespace Guzzle_Http\Uri_Template\Tests;
class Uri_Template_Test extends \PHPUnit\Framework\TestCase {
    /** @dataProvider template_Provider */
    public function test_x() {}
}
PHP;
$tmp = sys_get_temp_dir() . '/uri-rename-provider-' . getmypid() . '.php';
file_put_contents($tmp, $provider_demo);
scan_php_file($tmp, 'demo/provider-docblock.php', $allowlist, $demo_fail);
unlink($tmp);
if ($demo_fail === 0) {
    fwrite(STDERR, "check-rename: docblock @dataProvider template_Provider demo must fail.\n");
    $failures++;
}

$parse_demo = <<<'PHP'
<?php
namespace Guzzle_Http\Uri_Template;
final class Uri_Template {
    private static function parse_Expression(string $x): array { return array(); }
}
PHP;
$tmp = sys_get_temp_dir() . '/uri-rename-parse-' . getmypid() . '.php';
file_put_contents($tmp, $parse_demo);
$parse_fail = 0;
scan_php_file($tmp, 'demo/parse-expression.php', $allowlist, $parse_fail);
unlink($tmp);
if ($parse_fail === 0) {
    fwrite(STDERR, "check-rename: parse_Expression demo must fail.\n");
    $failures++;
}

$outside = <<<'PHP'
<?php
namespace Guzzle_Http\Uri_Template\Tests;
class Uri_Template_Test extends \PHPUnit\Framework\TestCase {
    public function test_x() { $this->assertTrue(true); }
}
PHP;
$tmp = sys_get_temp_dir() . '/uri-rename-outside-' . getmypid() . '.php';
file_put_contents($tmp, $outside);
$outside_fail = 0;
scan_php_file($tmp, 'demo/outside.php', $allowlist, $outside_fail);
unlink($tmp);
if ($outside_fail === 0) {
    fwrite(STDERR, "check-rename: outside-site demo must reject assertTrue without allowlist.\n");
    $failures++;
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "check-rename: snake_case convention holds; no forbidden upstream names remain.\n");
exit(0);
