---
description: Review or modify the WordPress connector plugin with awareness of its constraints and patterns
---

You are working on the SimpleAd Manager WordPress connector plugin located at `wordpress-plugin/simplead-manager-connector/`.

## Plugin Structure
- Main file: `simplead-manager-connector.php` (plugin header + bootstrap)
- REST endpoints: `includes/endpoints/class-*-endpoint.php`
- Core classes: `includes/class-*.php` (auth, security, admin, etc.)
- REST namespace: `simplead/v1`
- Current version: check `SAM_VERSION` constant in main file

## Critical Constraints
- **No `shell_exec`**: Target WP hosts have it disabled. Never use `shell_exec`, `exec`, `system`, or `passthru`
- **No composer/autoload**: This is a standalone WP plugin — use `require_once` or the SPL autoloader in the main file
- **PHP 7.4+ compatible**: Target sites may run PHP 7.4, so no PHP 8.x-only features (enums, named args, match expressions, union types in params, etc.)
- **Version sync**: Always keep `Version:` in the plugin header AND `SAM_VERSION` constant identical
- **Cloudflare**: Sites are behind Cloudflare — loopback requests get 403 challenge pages
- **Plugin push**: Uses signed URL route (`download.connector-plugin.signed`) — unsigned route requires Laravel auth

## Endpoint Pattern
Each endpoint class follows this structure:
- Extends or implements REST registration
- Registers routes under `simplead/v1/` namespace
- Uses API key authentication via `X-SAM-API-Key` header
- Returns `WP_REST_Response` or `WP_Error`

## When Modifying
1. Check current version: `grep SAM_VERSION simplead-manager-connector.php`
2. Test PHP syntax: `php -l <file>`
3. Ensure no shell_exec usage: `grep -r 'shell_exec\|exec(' includes/`
4. After changes, bump version in BOTH places if releasing

$ARGUMENTS
