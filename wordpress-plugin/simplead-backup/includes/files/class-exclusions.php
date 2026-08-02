<?php

declare(strict_types=1);

// Engine class — usable from WordPress (ABSPATH defined) and from the lab CLI harness.
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

/**
 * SAM_Backup_Exclusions
 * =====================
 * Deterministic rule engine deciding, from a relative path (+ its stat), whether a file is
 * kept or dropped from a backup. Applied BEFORE a file is read/hashed/zipped, so excluded
 * content never costs an I/O read.
 *
 * The manager aggregates rules across levels (global / client / site / schedule / manual) and
 * hands this class the resolved set — this class only APPLIES a set, it does not know levels.
 *
 * Supported rule types (each rule: {type, value|min/max/older_than/newer_than, action}):
 *   - folder    exact folder; matches the folder and everything beneath it
 *   - file      exact file path
 *   - glob      shell-style: `**` (any depth incl. `/`), `*` (within a segment), `?`
 *   - ext       file extension (no dot), case-insensitive
 *   - prefix    raw string prefix of the relative path
 *   - size      byte bounds {min?, max?} — matches files whose size is within the bounds
 *   - age       mtime bounds {older_than?, newer_than?} in seconds relative to "now"
 *
 * action is `exclude` (default) or `include`. Precedence (deterministic):
 *   1. any matching EXCLUDE rule → dropped (explicit exclude always wins);
 *   2. else if any include rules exist and none matched → dropped (allowlist / include-only);
 *   3. else kept.
 *
 * All paths are normalised to forward-slash, ABSPATH-relative, with `..` segments rejected
 * (path-traversal guard — nothing outside the walk root is ever matched or emitted).
 *
 * `policy_hash()` is a sha256 over the CANONICAL rule set (types/values/bounds, sorted),
 * NOT over resolved timestamps — so an `age` rule keeps the same hash regardless of when the
 * backup runs. A change in policy changes the hash → the orchestrator can force a fresh full.
 */
final class SAM_Backup_Exclusions {

    /** @var array<int,array<string,mixed>> normalised rules */
    private array $rules;

    /** @var bool whether any include (allowlist) rule is present */
    private bool $has_includes;

    private string $policy_hash;

    /**
     * @param array<int,mixed> $rules raw rules (assoc arrays or plain-string globs)
     */
    public function __construct(array $rules) {
        $normalised = array();
        foreach ($rules as $raw) {
            $rule = self::normalise_rule($raw);
            if ($rule !== null) {
                $normalised[] = $rule;
            }
        }

        // Deduplicate + order deterministically for a stable policy hash.
        $seen = array();
        $unique = array();
        foreach ($normalised as $rule) {
            $key = wp_json_encode_compat($rule);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $rule;
            }
        }
        usort($unique, static function ($a, $b): int {
            return strcmp(wp_json_encode_compat($a), wp_json_encode_compat($b));
        });

        $this->rules        = $unique;
        $this->has_includes = false;
        foreach ($unique as $rule) {
            if ($rule['action'] === 'include') {
                $this->has_includes = true;
                break;
            }
        }
        $this->policy_hash = hash('sha256', wp_json_encode_compat($unique));
    }

    /**
     * Build from a request-supplied set, optionally merged with the sensible defaults.
     *
     * @param array<int,mixed> $rules
     */
    public static function from_rules(array $rules, bool $include_defaults = true): self {
        if ($include_defaults) {
            $rules = array_merge(self::default_rules(), $rules);
        }
        return new self($rules);
    }

    /**
     * Sensible, overridable defaults. Generic (path-agnostic) globs so they apply under any
     * walk root: caches, VCS, dependency dirs, logs/temp/backup artefacts, known backup plugins.
     *
     * @return array<int,array<string,string>>
     */
    public static function default_rules(): array {
        return array(
            array('type' => 'glob', 'value' => 'wp-content/cache/**'),
            array('type' => 'glob', 'value' => 'wp-content/*/cache/**'),
            array('type' => 'glob', 'value' => '**/node_modules/**'),
            array('type' => 'glob', 'value' => '**/.git/**'),
            array('type' => 'ext',  'value' => 'log'),
            array('type' => 'ext',  'value' => 'tmp'),
            array('type' => 'ext',  'value' => 'bak'),
            array('type' => 'glob', 'value' => '**/error_log'),
            array('type' => 'glob', 'value' => '**/debug.log'),
            array('type' => 'glob', 'value' => 'wp-content/uploads/backup*/**'),
            array('type' => 'glob', 'value' => 'wp-content/updraft/**'),
            array('type' => 'glob', 'value' => 'wp-content/ai1wm-backups/**'),
            array('type' => 'glob', 'value' => '**/wflogs/**'),
            // Our own restore leftovers. A restore that is interrupted before commit or rollback
            // leaves a full copy of the pre-restore site in sam-restore-trash-*, inside ABSPATH —
            // and without this the next backup faithfully picks it up, doubling the site and
            // carrying the duplicate forward into every restore point after it. The hourly sweep
            // removes them; this makes sure a backup taken before the sweep is not poisoned.
            array('type' => 'glob', 'value' => 'sam-restore-trash-*/**'),
            array('type' => 'glob', 'value' => 'sam-restore-staging-*/**'),
        );
    }

    public function policy_hash(): string {
        return $this->policy_hash;
    }

    /** @return array<int,array<string,mixed>> */
    public function rules(): array {
        return $this->rules;
    }

    public function rule_count(): int {
        return count($this->rules);
    }

    /**
     * Decide whether a relative path is excluded. $size/$mtime come from the directory walk's
     * stat (NOT from reading the file), so size/age rules cost nothing extra.
     *
     * @param int|null $size  bytes (required for size rules)
     * @param int|null $mtime unix mtime (required for age rules)
     */
    public function is_excluded(string $relative_path, ?int $size = null, ?int $mtime = null, ?int $now = null): bool {
        $rel = self::normalise_path($relative_path);
        if ($rel === null) {
            // Un-normalisable / traversal → refuse to include (fail safe).
            return true;
        }
        $now = $now ?? time();

        $matched_include = false;
        foreach ($this->rules as $rule) {
            if (!self::rule_matches($rule, $rel, $size, $mtime, $now)) {
                continue;
            }
            if ($rule['action'] === 'exclude') {
                return true; // explicit exclude wins immediately
            }
            $matched_include = true;
        }

        if ($this->has_includes && !$matched_include) {
            return true; // allowlist active and this path is not on it
        }
        return false;
    }

    // ---------------------------------------------------------------------------------
    // Normalisation
    // ---------------------------------------------------------------------------------

    /**
     * Normalise one raw rule into canonical form, or null if unusable.
     *
     * @param mixed $raw
     * @return array<string,mixed>|null
     */
    private static function normalise_rule($raw): ?array {
        // Convenience: a bare string is a glob exclude.
        if (is_string($raw)) {
            $raw = array('type' => 'glob', 'value' => $raw);
        }
        if (!is_array($raw)) {
            return null;
        }

        $type   = isset($raw['type']) ? strtolower((string) $raw['type']) : 'glob';
        $action = isset($raw['action']) && strtolower((string) $raw['action']) === 'include' ? 'include' : 'exclude';

        switch ($type) {
            case 'folder':
            case 'file':
            case 'prefix':
            case 'glob':
                $value = self::normalise_path((string) ($raw['value'] ?? ''));
                if ($value === null || $value === '') {
                    return null;
                }
                return array('type' => $type, 'value' => $value, 'action' => $action);

            case 'ext':
                $value = strtolower(ltrim((string) ($raw['value'] ?? ''), '.'));
                if ($value === '') {
                    return null;
                }
                return array('type' => 'ext', 'value' => $value, 'action' => $action);

            case 'size':
                $min = isset($raw['min']) && $raw['min'] !== null ? (int) $raw['min'] : null;
                $max = isset($raw['max']) && $raw['max'] !== null ? (int) $raw['max'] : null;
                if ($min === null && $max === null) {
                    return null;
                }
                return array('type' => 'size', 'min' => $min, 'max' => $max, 'action' => $action);

            case 'age':
                $older = isset($raw['older_than']) && $raw['older_than'] !== null ? (int) $raw['older_than'] : null;
                $newer = isset($raw['newer_than']) && $raw['newer_than'] !== null ? (int) $raw['newer_than'] : null;
                if ($older === null && $newer === null) {
                    return null;
                }
                return array('type' => 'age', 'older_than' => $older, 'newer_than' => $newer, 'action' => $action);

            default:
                return null;
        }
    }

    /**
     * Normalise a relative path: forward slashes, collapse `//`, strip `./`, trim leading/trailing
     * slashes. Returns null if it escapes the root (`..` present) — the path-traversal guard.
     */
    public static function normalise_path(string $path): ?string {
        $p = str_replace('\\', '/', $path);
        // Collapse runs of slashes.
        $p = preg_replace('#/+#', '/', $p);
        $p = trim((string) $p, '/');
        if ($p === '') {
            return '';
        }
        $out = array();
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null; // traversal — reject outright
            }
            $out[] = $seg;
        }
        return implode('/', $out);
    }

    // ---------------------------------------------------------------------------------
    // Matching
    // ---------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $rule
     */
    private static function rule_matches(array $rule, string $rel, ?int $size, ?int $mtime, int $now): bool {
        switch ($rule['type']) {
            case 'folder':
                $v = $rule['value'];
                return $rel === $v || strpos($rel, $v . '/') === 0;

            case 'file':
                return $rel === $rule['value'];

            case 'prefix':
                $v = $rule['value'];
                return $v !== '' && strpos($rel, $v) === 0;

            case 'ext':
                return strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === $rule['value'];

            case 'glob':
                return (bool) preg_match(self::glob_to_regex($rule['value']), $rel);

            case 'size':
                if ($size === null) {
                    return false; // no stat → cannot decide, treat as no-match
                }
                if ($rule['min'] !== null && $size < $rule['min']) {
                    return false;
                }
                if ($rule['max'] !== null && $size > $rule['max']) {
                    return false;
                }
                return true;

            case 'age':
                if ($mtime === null) {
                    return false;
                }
                $age = $now - $mtime;
                if ($rule['older_than'] !== null && !($age > $rule['older_than'])) {
                    return false;
                }
                if ($rule['newer_than'] !== null && !($age < $rule['newer_than'])) {
                    return false;
                }
                return true;
        }
        return false;
    }

    /**
     * Translate a shell-style glob to an anchored regex.
     *   double-star + slash  -> optional "zero-or-more leading directories"
     *   double-star          -> any depth (matches across slashes)
     *   single-star          -> any run WITHIN one path segment (no slash)
     *   question-mark        -> one non-slash char
     * Everything else is quoted literally.
     */
    private static function glob_to_regex(string $glob): string {
        $g   = str_replace('\\', '/', $glob);
        $len = strlen($g);
        $re  = '';
        for ($i = 0; $i < $len; $i++) {
            $c = $g[$i];
            if ($c === '*') {
                if ($i + 1 < $len && $g[$i + 1] === '*') {
                    if ($i + 2 < $len && $g[$i + 2] === '/') {
                        $re .= '(?:.*/)?';
                        $i  += 2; // consume the second `*` and the `/`
                    } else {
                        $re .= '.*';
                        $i  += 1; // consume the second `*`
                    }
                } else {
                    $re .= '[^/]*';
                }
            } elseif ($c === '?') {
                $re .= '[^/]';
            } else {
                $re .= preg_quote($c, '#');
            }
        }
        return '#^' . $re . '$#';
    }
}

/**
 * Canonical JSON with sorted keys — deterministic across PHP builds. Small helper so the
 * policy hash is reproducible regardless of insertion order. Prefixed to avoid clashing with
 * WordPress core if this file is ever loaded standalone (lab CLI).
 */
if (!function_exists('wp_json_encode_compat')) {
    function wp_json_encode_compat($value): string {
        if (is_array($value)) {
            $is_list = array_keys($value) === range(0, count($value) - 1);
            if (!$is_list) {
                ksort($value);
            }
            $parts = array();
            foreach ($value as $k => $v) {
                $parts[] = ($is_list ? '' : json_encode((string) $k) . ':') . wp_json_encode_compat($v);
            }
            return ($is_list ? '[' : '{') . implode(',', $parts) . ($is_list ? ']' : '}');
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
