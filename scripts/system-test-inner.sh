#!/usr/bin/env bash
set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1

strip_ansi() {
    sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g' "$@"
}

fail_deprecation_output() {
    local label="$1"
    local file="$2"
    local stripped
    stripped="$(mktemp)"
    strip_ansi "${file}" >"${stripped}"
    if grep -E '^(PHP )?(Warning|Deprecated|Notice):' "${stripped}" >/dev/null; then
        echo "${label}: output contains Warning, Deprecated, or Notice lines" >&2
        grep -E '^(PHP )?(Warning|Deprecated|Notice):' "${stripped}" >&2 || true
        rm -f "${stripped}"
        exit 1
    fi
    rm -f "${stripped}"
}

assert_stripped_line() {
    local pattern="$1"
    local file="$2"
    local stripped
    stripped="$(mktemp)"
    strip_ansi "${file}" >"${stripped}"
    if ! grep -Fxq "${pattern}" "${stripped}" 2>/dev/null && ! grep -E "${pattern}" "${stripped}" >/dev/null; then
        rm -f "${stripped}"
        return 1
    fi
    rm -f "${stripped}"
    return 0
}

php_out="$(mktemp)"
php -v | tee "${php_out}"
if ! assert_stripped_line '^PHP 7\.2\.34' "${php_out}"; then
    echo 'system-test: missing PHP 7.2.34 version line' >&2
    rm -f "${php_out}"
    exit 1
fi
rm -f "${php_out}"

for ext in dom filter json mbstring phar tokenizer xmlwriter; do
    if ! php -m | grep -qi "^${ext}$"; then
        echo "Missing required PHP extension: ${ext}" >&2
        exit 1
    fi
done

COMPOSER_URL="https://getcomposer.org/download/2.8.10/composer.phar"
COMPOSER_SHA256="28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573"
curl -fsSL -o /tmp/composer.phar "${COMPOSER_URL}"
echo "${COMPOSER_SHA256}  /tmp/composer.phar" | sha256sum -c -

git config --global --add safe.directory /app
rm -rf vendor composer.lock

composer_out="$(mktemp)"
set +e
php /tmp/composer.phar update --no-interaction --prefer-dist --no-progress >"${composer_out}" 2>&1
composer_status=$?
set -e
cat "${composer_out}"
fail_deprecation_output "composer update" "${composer_out}"
if [[ ${composer_status} -ne 0 ]]; then
    rm -f "${composer_out}"
    exit "${composer_status}"
fi
rm -f "${composer_out}"

php scripts/check-cluster-pins.php
php scripts/check-rename.php
php scripts/check-no-display-errors.php
php scripts/check-apt-policy.php

audit_out="$(mktemp)"
set +e
php /tmp/composer.phar audit --no-interaction --abandoned=report >"${audit_out}" 2>&1
audit_status=$?
set -e
cat "${audit_out}"
fail_deprecation_output "composer audit" "${audit_out}"
if [[ ${audit_status} -ne 0 ]]; then
    exit "${audit_status}"
fi
if ! assert_stripped_line 'No security vulnerability advisories found.' "${audit_out}"; then
    echo 'composer audit: missing exact success line' >&2
    exit 1
fi
rm -f "${audit_out}"

phpunit_out="$(mktemp)"
set +e
php -d error_reporting=32767 vendor/bin/phpunit --colors=never --do-not-cache-result >"${phpunit_out}" 2>&1
phpunit_status=$?
set -e
cat "${phpunit_out}"
fail_deprecation_output "phpunit" "${phpunit_out}"
if [[ ${phpunit_status} -ne 0 ]]; then
    exit "${phpunit_status}"
fi

php scripts/check-phpunit-gate.php "${phpunit_out}"
rm -f "${phpunit_out}"

echo 'system-test: all steps passed.'
