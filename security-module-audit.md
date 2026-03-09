# Security Module — Audit Report

**Date:** 2026-03-06
**Auditor:** Claude (automated)
**Module version:** Initial release

---

## Overall Score: 88/100 — Good (post-audit)

| Category | Score | Weight | Notes |
|---|---|---|---|
| Architecture & Design | 18/20 | 20% | Clean separation, agent-based command pattern, good service layer |
| Code Quality | 17/20 | 20% | Duplication eliminated, consistent patterns, proper DI |
| Security Practices | 18/20 | 20% | Good validation, rate limiting, CIDR validation fixed, IP scoping fixed |
| Database Design | 17/20 | 20% | Well-indexed, proper FK cascades, good use of JSONB |
| Frontend/UX | 18/20 | 20% | Consistent UI, good component decomposition, pagination added |

---

## Architecture Overview

The Security module follows an **agent-based command queue** pattern:

```
User → Livewire Component → Service Layer → SecurityCommand (DB queue)
                                                    ↓
                                         WP Agent (picks up & executes)
                                                    ↓
                                         Agent API → processCommandResult()
```

**Key design decisions:**
- Commands are queued in the database (not Laravel jobs) so the WP plugin agent can poll for them
- Settings are tracked independently of commands — a setting can be "enabled" but not yet "applied"
- Security scans run as Laravel jobs with progress tracking via `JobTracker`
- Presets allow bulk-applying settings across multiple sites

---

## File Inventory (53 files)

### Models (12 files, ~880 lines)

| File | Purpose |
|---|---|
| `app/Models/SecurityScan.php` | Scan results with score and issue counts |
| `app/Models/SecurityIssue.php` | Individual security issues detected by scans |
| `app/Models/SecuritySetting.php` | Per-site hardening settings (enabled/applied state) |
| `app/Models/SecurityCommand.php` | Agent command queue with status tracking |
| `app/Models/SecurityRecommendation.php` | Security best-practice recommendations |
| `app/Models/SecurityPreset.php` | Reusable setting presets |
| `app/Models/SecurityMonitor.php` | Scheduled scan configuration |
| `app/Models/SecurityActivityLog.php` | WordPress activity log entries |
| `app/Models/SecurityIpList.php` | IP whitelist/blacklist management |
| `app/Models/SecurityBannedIp.php` | Automatically banned IPs |
| `app/Models/SslCertificate.php` | SSL certificate tracking |
| `app/Models/SslCheckHistory.php` | SSL check history records |

### Services (6 files, ~830 lines)

| File | Purpose |
|---|---|
| `app/Services/SecurityScanService.php` | Orchestrates security scans, calculates scores |
| `app/Services/SecuritySettingsService.php` | Manages settings, score weights, presets |
| `app/Services/SecurityCommandService.php` | Command creation, processing, cleanup |
| `app/Services/SecurityRecommendationService.php` | Recommendation checks and auto-fixes |
| `app/Services/SecurityActivityService.php` | Activity log ingestion and queries |
| `app/Services/SecurityPresetService.php` | Preset CRUD operations |

### Enums (4 files, ~120 lines)

| File | Purpose |
|---|---|
| `app/Enums/SecurityCategory.php` | Setting/command categories (hardening, login, etc.) |
| `app/Enums/SecuritySettingStatus.php` | Setting status with color helpers |
| `app/Enums/SecurityCommandStatus.php` | Command lifecycle states |
| `app/Enums/SecurityCommandPriority.php` | Command priority levels |

### Livewire Components (12 files, ~1060 lines)

| File | Purpose |
|---|---|
| `app/Livewire/Security/SecurityDashboard.php` | Cross-site security overview |
| `app/Livewire/Security/PresetManager.php` | Preset management UI |
| `app/Livewire/Sites/Detail/SiteSecurity.php` | Per-site security hub (tabs) |
| `app/Livewire/Sites/Detail/Security/HardeningSettings.php` | WordPress hardening toggles |
| `app/Livewire/Sites/Detail/Security/LoginProtection.php` | Login protection config |
| `app/Livewire/Sites/Detail/Security/ActivityLog.php` | Activity log viewer |
| `app/Livewire/Sites/Detail/Security/IpManagement.php` | IP whitelist/blacklist UI |
| `app/Livewire/Sites/Detail/Security/CaptchaSettings.php` | CAPTCHA configuration |
| `app/Livewire/Sites/Detail/Security/SiteUsers.php` | WordPress user management |

### Blade Views (16 files, ~1740 lines)

| File | Purpose |
|---|---|
| `resources/views/livewire/security/security-dashboard.blade.php` | Dashboard layout |
| `resources/views/livewire/sites/detail/site-security.blade.php` | Per-site security page |
| `resources/views/livewire/sites/detail/security/_overview.blade.php` | Hardening overview tab |
| `resources/views/livewire/sites/detail/security/_scanning.blade.php` | Scan results tab |
| `resources/views/livewire/sites/detail/security/hardening-settings.blade.php` | Hardening toggles |
| `resources/views/livewire/sites/detail/security/login-protection.blade.php` | Login protection UI |
| `resources/views/livewire/sites/detail/security/activity-log.blade.php` | Activity log view |
| `resources/views/livewire/sites/detail/security/ip-management.blade.php` | IP management UI |
| `resources/views/livewire/sites/detail/security/captcha-settings.blade.php` | CAPTCHA config UI |
| `resources/views/livewire/sites/detail/security/site-users.blade.php` | User management view |
| `resources/views/livewire/sites/detail/overview/_security-card.blade.php` | Overview dashboard card |
| `resources/views/components/security/score-circle.blade.php` | Reusable score circle SVG |
| `resources/views/components/security/ssl-card.blade.php` | SSL certificate card |
| `resources/views/components/hovercards/ssl.blade.php` | SSL hovercard |
| `resources/views/reports/partials/security.blade.php` | Security report section |
| `resources/views/reports/partials/security-checks.blade.php` | Security checks in reports |

### Migrations (10 files)

| File |
|---|
| `2026_02_02_080001_create_ssl_certificates_table.php` |
| `2026_02_02_080002_create_ssl_check_history_table.php` |
| `2026_02_06_100001_create_security_scans_table.php` |
| `2026_02_06_100002_create_security_issues_table.php` |
| `2026_02_06_200001_create_security_recommendations_table.php` |
| `2026_02_06_300001_create_vulnerability_alerts_table.php` |
| `2026_02_13_000004_create_security_monitors_table.php` |
| `2026_03_03_000001_create_security_hardening_tables.php` |
| `2026_03_03_000002_add_security_score_to_sites.php` |
| `2026_03_03_000003_add_security_columns_to_site_users.php` |

---

## Strengths

1. **Agent-based command queue** — Clean separation between the management app and WordPress sites. Commands are queued in DB, agents poll and execute, results flow back.
2. **Good use of enums** — `SecurityCommandStatus`, `SecurityCommandPriority`, `SecurityCategory`, `SecuritySettingStatus` provide type safety.
3. **Proper rate limiting** — Scan, SSL check, and integrity check actions are all rate-limited per user per site.
4. **Well-structured migrations** — Proper indexing on foreign keys and query columns, FK cascades, JSONB for flexible payloads.
5. **Clean Livewire decomposition** — Main `SiteSecurity` component with sub-components for each tab (hardening, login, captcha, IP, activity, users).
6. **Computed properties with cache invalidation** — Proper use of `#[Computed]` with `unset()` for cache busting on mutations.
7. **Consistent UI patterns** — Uses shared `x-ui.*` components throughout.

---

## Issues Found & Remediation

### High Priority — Fixed

| # | Issue | Fix Applied |
|---|---|---|
| 1 | Duplicated severity ordering SQL in `SiteSecurity.php` | Added `scopeOrderBySeverity()` to `SecurityIssue` and `VulnerabilityAlert` models |
| 2 | Duplicated score circle SVG in `_overview.blade.php` and `_scanning.blade.php` | Extracted `<x-security.score-circle>` Blade component |
| 3 | `SecurityScanService` used `match` with IIFEs for counting | Replaced with simple penalty map and loop |
| 4 | `SecurityScanService::scan()` was a 130-line monolith | Extracted into `checkWpVersion()`, `checkCoreIntegrity()`, `checkDebugMode()`, `checkSsl()`, `upsertIssues()`, `calculateScore()` |
| 5 | Inconsistent static vs instance methods | Converted `SecurityScanService` and `SecurityRecommendationService` to instance methods, registered as singletons |
| 6 | N+1 on `securitySettings` in `SecurityDashboard` | Added `->with('securitySettings')` to eager-load |
| 7 | `SecuritySettingsService::applySetting()` duplicated command creation | Delegated to `SecurityCommandService::createCommand()` via constructor injection |

### Medium Priority — Fixed

| # | Issue | Fix Applied |
|---|---|---|
| 8 | `SecurityCategory` enum not used in models | Added `'category' => SecurityCategory::class` cast to `SecuritySetting` and `SecurityCommand` |
| 9 | `SecuritySettingStatus` enum not used | `getStatusAttribute()` now returns `SecuritySettingStatus` enum, `getStatusColorAttribute()` delegates to `->color()` |
| 10 | `firewall_enabled` key mismatch in `SCORE_WEIGHTS` | Changed to `firewall_config` to match actual setting key |

### Low Priority — Fixed

| # | Issue | Fix Applied |
|---|---|---|
| 11 | Score color logic repeated in 4+ places | Added `SecurityScan::scoreColor(int)` static helper, used in views and component |
| 12 | Missing return types on computed properties | Added return types to all `SiteSecurity` computed properties |
| 13 | Dead `$this->sslHistory` in `unset()` call | Removed from `checkSslNow()` |

### Acknowledged — No Change Needed

| # | Issue | Rationale |
|---|---|---|
| 14 | `ingestLogs()` manual `json_encode` | Bulk `insert()` bypasses Eloquent casts by design. Manual encoding is correct. |
| 15 | `PresetManager::availableSites` loads all sites | Acceptable — admin-only feature, site count expected to be <500 |

---

## Files Modified

| File | Changes |
|---|---|
| `app/Models/SecurityIssue.php` | Added `scopeOrderBySeverity()` |
| `app/Models/VulnerabilityAlert.php` | Added `scopeOrderBySeverity()` |
| `app/Models/SecuritySetting.php` | Added `SecurityCategory` + `SecuritySettingStatus` enum casts |
| `app/Models/SecurityCommand.php` | Added `SecurityCategory` enum cast |
| `app/Models/SecurityScan.php` | Added static `scoreColor()` helper |
| `app/Services/SecurityScanService.php` | Converted to instance, extracted private methods, simplified score calc |
| `app/Services/SecurityRecommendationService.php` | Converted static to instance methods |
| `app/Services/SecuritySettingsService.php` | Fixed score weight key, delegated command creation via DI |
| `app/Livewire/Security/SecurityDashboard.php` | Eager-loaded `securitySettings` |
| `app/Livewire/Sites/Detail/SiteSecurity.php` | Added return types, removed dead unset, used model scopes |
| `app/Jobs/RunSecurityScan.php` | Updated to use instance method |
| `app/Jobs/SyncWordPressSite.php` | Updated to use instance method |
| `app/Providers/AppServiceProvider.php` | Registered `SecurityScanService` and `SecurityRecommendationService` singletons |
| `resources/views/components/security/score-circle.blade.php` | **New** — extracted score circle component |
| `resources/views/livewire/sites/detail/security/_overview.blade.php` | Uses score-circle component, enum comparisons |
| `resources/views/livewire/sites/detail/security/_scanning.blade.php` | Uses score-circle component |
| `resources/views/livewire/security/security-dashboard.blade.php` | Uses `SecurityScan::scoreColor()` |

---

## Round 2 — Deep Audit Issues & Remediation

### Critical / High — Fixed

| # | Issue | Fix Applied |
|---|---|---|
| 16 | `IpManagement::removeIp()` allowed deleting global IPs via `orWhereNull('site_id')` | Scoped deletion strictly to current site |
| 17 | Weak CIDR validation accepted invalid prefixes (e.g. `/33` for IPv4) | Proper validation with IPv4 (0-32) and IPv6 (0-128) range checks |
| 18 | Race condition in `SecurityCommandService::createCommand()` | Wrapped cancel + create in `DB::transaction()` |
| 19 | `SiteUsers` loaded all users without pagination | Added `WithPagination`, `->paginate(50)`, and pagination links in view |
| 20 | `CaptchaSettings` allowed saving siteKey without secretKey | Added `required_with:siteKey` validation for secretKey when updating keys |

### Medium — Fixed

| # | Issue | Fix Applied |
|---|---|---|
| 21 | `SecurityDashboard` eager-loaded all `securitySettings` just for counts | Replaced with `withCount('securitySettings as enabled_settings_count')` and `addSelect` subquery for `last_security_sync` |
| 22 | `ActivityLog::filterDays` unbounded — user could set to 99999 | Added `max(1, min(365, ...))` clamp in `updatedFilterDays()` |
| 23 | `getFailedLoginStats()` duplicated base query conditions | Extracted shared `$baseQuery` with `clone` for stats and topIps |
| 24 | Hardcoded WP version thresholds (`6.4`, `6.0`) in scan service | Extracted to `config/security.php` with `WP_MIN_VERSION` and `WP_RECOMMENDED_VERSION` env vars |

### Low — Fixed

| # | Issue | Fix Applied |
|---|---|---|
| 25 | `SecurityPresetService::applyToSites()` was unused passthrough | Removed dead method |
| 26 | `SecurityPresetService::getPresetDiff()` didn't guard against corrupted data | Added `is_array()` checks for category and config items |

---

## Additional Files Modified (Round 2)

| File | Changes |
|---|---|
| `app/Livewire/Sites/Detail/Security/IpManagement.php` | Removed global IP deletion, proper CIDR validation |
| `app/Livewire/Sites/Detail/Security/CaptchaSettings.php` | Fixed key validation, clearer encryption handling |
| `app/Livewire/Sites/Detail/Security/SiteUsers.php` | Added pagination |
| `app/Livewire/Sites/Detail/Security/ActivityLog.php` | Clamped filterDays to 1-365 |
| `app/Livewire/Security/SecurityDashboard.php` | Replaced eager-load with withCount + subselect |
| `app/Services/SecurityCommandService.php` | Wrapped createCommand in DB transaction |
| `app/Services/SecurityActivityService.php` | Deduplicated base query in getFailedLoginStats |
| `app/Services/SecurityPresetService.php` | Removed dead passthrough, added array guards |
| `app/Services/SecurityScanService.php` | WP version thresholds from config |
| `config/security.php` | **New** — security configuration file |
| `resources/views/livewire/sites/detail/security/site-users.blade.php` | Added pagination links |
| `resources/views/livewire/security/security-dashboard.blade.php` | Uses computed columns instead of collection methods |

---

## Verification Checklist

- [ ] `php artisan test --filter=Security` — all tests pass
- [ ] PHPStan analysis on modified files
- [ ] Visit `/security` dashboard — stats, filters, table render correctly
- [ ] Visit `/sites/{id}/security` — all tabs render, score circles display
- [ ] Toggle a setting — command is created via `SecurityCommandService`
- [ ] `firewall_config` key contributes to hardening score
- [ ] Enum values match existing DB strings (lowercase backed enums)
- [ ] IP management: add/remove IPs, verify CIDR validation rejects `/33`
- [ ] SiteUsers tab paginates correctly at 50 per page
- [ ] CAPTCHA: save with new keys, save preserving existing keys
- [ ] Activity log: filterDays clamps to 1-365 range
