#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fail if any tracked file sets display_errors (no swallowing warnings in CI).
 */

$root = dirname(__DIR__);
chdir($root);

$raw = shell_exec('git ls-files -z 2>/dev/null');
if (!is_string($raw)) {
    fwrite(STDERR, "check-no-display-errors: could not list tracked files.\n");
    exit(1);
}

$failures = array();
foreach (explode("\0", $raw) as $rel) {
    if ($rel === '' || !is_file($rel)) {
        continue;
    }
    if ($rel === 'scripts/check-no-display-errors.php') {
        continue;
    }
    if (!preg_match('/\.(php|sh|ini|dockerfile)$/i', $rel) && !preg_match('#(^|/)Dockerfile(\.|$)#i', $rel)) {
        continue;
    }
    $text = file_get_contents($rel);
    if ($text === false) {
        continue;
    }
    if (preg_match('/ini_set\s*\(\s*[\'"]display_errors[\'"]/i', $text)) {
        $failures[] = $rel;
    }
    if (preg_match('/-d\s+display_errors/i', $text)) {
        $failures[] = $rel;
    }
}

$demo = "<?php\nini_set('display_errors', '0');\n";
if (!preg_match('/ini_set\s*\(\s*[\'"]display_errors[\'"]/i', $demo)) {
    fwrite(STDERR, "check-no-display-errors: in-memory demo invalid.\n");
    exit(1);
}

if (count($failures) > 0) {
    fwrite(STDERR, "check-no-display-errors: display_errors override in tracked files:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  {$f}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK no-display-errors override\n");
exit(0);
