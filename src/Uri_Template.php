<?php

declare (strict_types=1);
namespace Guzzle_Http\Uri_Template;

/**
 * Expands URI templates. Userland implementation of PECL uri_template.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6570
 */
final class Uri_Template
{
    /**
     * @var array<string, array{prefix:string, joiner:string, query:bool}> Hash for quick operator lookups
     */
    private static $operator_hash = ['' => ['prefix' => '', 'joiner' => ',', 'query' => false], '+' => ['prefix' => '', 'joiner' => ',', 'query' => false], '#' => ['prefix' => '#', 'joiner' => ',', 'query' => false], '.' => ['prefix' => '.', 'joiner' => '.', 'query' => false], '/' => ['prefix' => '/', 'joiner' => '/', 'query' => false], ';' => ['prefix' => ';', 'joiner' => ';', 'query' => true], '?' => ['prefix' => '?', 'joiner' => '&', 'query' => true], '&' => ['prefix' => '&', 'joiner' => '&', 'query' => true]];
    /**
     * @var string[] Delimiters
     */
    private static $delims = [':', '/', '?', '#', '[', ']', '@', '!', '$', '&', '\'', '(', ')', '*', '+', ',', ';', '='];
    /**
     * @var string[] Percent encoded delimiters
     */
    private static $delims_pct = ['%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D'];
    /**
     * @param array<string,mixed> $variables Variables to use in the template expansion
     *
     * @throws \RuntimeException
     */
    public static function expand(string $template, array $variables): string
    {
        if (!str_contains($template, '{')) {
            return $template;
        }
        /** @var string|null */
        $result = \preg_replace_callback('/\{([^\}]+)\}/', self::expand_match_callback($variables), $template);
        if (null === $result) {
            throw new \RuntimeException(\sprintf('Unable to process template: %s', \preg_last_error_msg()));
        }
        return $result;
    }
    /**
     * @param array<string,mixed> $variables Variables to use in the template expansion
     *
     * @return callable(string[]): string
     */
    private static function expand_match_callback(array $variables): callable
    {
        return static function (array $matches) use ($variables): string {
            return self::expand_match($matches, $variables);
        };
    }
    /**
     * Process an expansion
     *
     * @param array<string,mixed> $variables Variables to use in the template expansion
     * @param string[]            $matches   Matches met in the preg_replace_callback
     *
     * @return string Returns the replacement string
     */
    private static function expand_match(array $matches, array $variables): string
    {
        $replacements = [];
        $parsed = self::parse_expression($matches[1]);
        $prefix = self::$operator_hash[$parsed['operator']]['prefix'];
        $joiner = self::$operator_hash[$parsed['operator']]['joiner'];
        $use_query = self::$operator_hash[$parsed['operator']]['query'];
        $all_undefined = true;
        foreach ($parsed['values'] as $value) {
            if (!isset($variables[$value['value']])) {
                continue;
            }
            $variable = $variables[$value['value']];
            $actually_use_query = $use_query;
            $expanded = '';
            if (\is_array($variable)) {
                $is_assoc = self::is_assoc($variable);
                $kvp = [];
                /** @var mixed $var */
                foreach ($variable as $key => $var) {
                    if ($is_assoc) {
                        $key = \rawurlencode((string) $key);
                        $is_nested_array = \is_array($var);
                    } else {
                        $is_nested_array = false;
                    }
                    if (!$is_nested_array) {
                        $var = \rawurlencode((string) $var);
                        if ($parsed['operator'] === '+' || $parsed['operator'] === '#') {
                            $var = self::decode_reserved($var);
                        }
                    }
                    if ($value['modifier'] === '*') {
                        if ($is_assoc) {
                            if ($is_nested_array) {
                                // Nested arrays must allow for deeply nested structures.
                                $var = \http_build_query([$key => $var], '', '&', \PHP_QUERY_RFC3986);
                            } else {
                                $var = \sprintf('%s=%s', (string) $key, (string) $var);
                            }
                        } elseif ($key > 0 && $actually_use_query) {
                            $var = \sprintf('%s=%s', $value['value'], (string) $var);
                        }
                    }
                    /** @var string $var */
                    $kvp[$key] = $var;
                }
                if (0 === \count($variable)) {
                    $actually_use_query = false;
                } elseif ($value['modifier'] === '*') {
                    $expanded = \implode($joiner, $kvp);
                    if ($is_assoc) {
                        // Don't prepend the value name when using the explode
                        // modifier with an associative array.
                        $actually_use_query = false;
                    }
                } else {
                    if ($is_assoc) {
                        // When an associative array is encountered and the
                        // explode modifier is not set, then the result must be
                        // a comma separated list of keys followed by their
                        // respective values.
                        foreach ($kvp as $k => &$v) {
                            $v = \sprintf('%s,%s', $k, $v);
                        }
                    }
                    $expanded = \implode(',', $kvp);
                }
            } else {
                $all_undefined = false;
                if ($value['modifier'] === ':' && isset($value['position'])) {
                    $variable = \substr((string) $variable, 0, $value['position']);
                }
                $expanded = \rawurlencode((string) $variable);
                if ($parsed['operator'] === '+' || $parsed['operator'] === '#') {
                    $expanded = self::decode_reserved($expanded);
                }
            }
            if ($actually_use_query) {
                if ($expanded === '' && $joiner !== '&') {
                    $expanded = $value['value'];
                } else {
                    $expanded = \sprintf('%s=%s', $value['value'], $expanded);
                }
            }
            $replacements[] = $expanded;
        }
        $ret = \implode($joiner, $replacements);
        if ('' === $ret) {
            // Spec section 3.2.4 and 3.2.5
            if (false === $all_undefined && ('#' === $prefix || '.' === $prefix)) {
                return $prefix;
            }
        } else if ('' !== $prefix) {
            return \sprintf('%s%s', $prefix, $ret);
        }
        return $ret;
    }
    /**
     * Parse an expression into parts
     *
     * @param string $expression Expression to parse
     *
     * @return array{operator:string, values:array<array{value:string, modifier:(''|'*'|':'), position?:int}>}
     */
    private static function parse_expression(string $expression): array
    {
        $result = [];
        if (isset(self::$operator_hash[$expression[0]])) {
            $result['operator'] = $expression[0];
            $expression = \substr($expression, 1);
        } else {
            $result['operator'] = '';
        }
        $result['values'] = [];
        foreach (\explode(',', $expression) as $value) {
            $value = \trim($value);
            $varspec = [];
            if ($colon_pos = \strpos($value, ':')) {
                $varspec['value'] = (string) \substr($value, 0, $colon_pos);
                $varspec['modifier'] = ':';
                $varspec['position'] = (int) \substr($value, $colon_pos + 1);
            } elseif (str_ends_with($value, '*')) {
                $varspec['modifier'] = '*';
                $varspec['value'] = (string) \substr($value, 0, -1);
            } else {
                $varspec['value'] = $value;
                $varspec['modifier'] = '';
            }
            $result['values'][] = $varspec;
        }
        return $result;
    }
    /**
     * Determines if an array is associative.
     *
     * This makes the assumption that input arrays are sequences or hashes.
     * This assumption is a tradeoff for accuracy in favor of speed, but it
     * should work in almost every case where input is supplied for a URI
     * template.
     */
    private static function is_assoc(array $array): bool
    {
        return $array && \array_keys($array)[0] !== 0;
    }
    /**
     * Removes percent encoding on reserved characters (used with + and #
     * modifiers).
     */
    private static function decode_reserved(string $string): string
    {
        return \str_replace(self::$delims_pct, self::$delims, $string);
    }
}