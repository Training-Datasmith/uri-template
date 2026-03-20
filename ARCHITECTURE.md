# Architecture: uri-template

## Purpose

A PHP implementation of RFC 6570 URI Templates (Guzzle's userland equivalent of the `uri_template` PECL extension). Expands URI template strings like `https://api.example.com/users/{user}` with variable values.

## Directory Structure

```
src/
  Uri_Template.php   - Single final class implementing RFC 6570 template expansion
```

## Key Design Decisions

- **Single class**: The entire implementation lives in one file with static methods — no instantiation needed. This matches the PECL `uri_template()` function API it replaces.
- **Operator hash table**: All eight RFC 6570 operators (`+`, `#`, `.`, `/`, `;`, `?`, `&`) and the default (no operator) are encoded in a static `$operator_hash` lookup table for O(1) operator dispatch.
- **Percent-encoding**: Correctly percent-encodes variable values per RFC 3986 while preserving reserved characters for operators that allow them (e.g., `+`).
- **Minimal dependencies**: No external dependencies beyond PHP itself.

## Extension Points

- No extension points — this is an RFC implementation. Use the `uri` (League URI) library if you need a full-featured, extensible URI manipulation library.

## Dependency Flow

```
Uri_Template::expand(string $template, array $variables): string
  └─> parses {expression} blocks from $template
  └─> for each expression: applies operator rules from $operator_hash
        └─> encodes variable values per RFC 3986 rules
  └─> returns expanded URI string
```
