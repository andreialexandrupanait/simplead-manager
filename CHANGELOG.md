# Changelog

All notable changes to SimpleAd Manager will be documented in this file.

## [1.0.0] - 2026-02-08

### Added
- Health check endpoint (`/health`) with DB, Redis, Horizon, and disk status
- Custom error pages (404, 500, 503) with branded dark theme
- Authentication event logging (login, logout, failed attempts with IP tracking)
- Two-factor authentication (TOTP) with QR code setup and recovery codes
- System alerts for Horizon downtime, long queue waits, and repeated job failures
- Notification events: `horizon_stopped`, `horizon_long_wait`, `job_failures`
- Database maintenance schedulers (VACUUM, activity purge, performance purge, failed jobs cleanup)
- Localization foundation with English and Romanian translations
- Language switcher in profile settings
- SetLocale middleware for per-user language preference
- Application self-backup and restore
- Production Docker Compose configuration
- Deployment script (`deploy.sh`)
- Production environment template (`.env.production.example`)
- README, DEPLOYMENT, and CHANGELOG documentation

### Changed
- All retryable jobs now have exponential backoff arrays
- SyncWordPressSite backoff changed from integer to array `[30, 60, 120]`
- FetchAnalyticsData and FetchSearchConsoleData now have `$tries`, `$timeout`, and `$backoff`
- User model: `two_factor_secret` cast to `encrypted`, `two_factor_recovery_codes` to `encrypted:array`
- Security headers middleware applied globally
- TrustProxies configured for reverse proxy support

### Security
- Two-factor authentication support (pragmarx/google2fa)
- Failed login attempt logging with IP and user agent
- Session security (encrypted, secure cookies in production)
- Security headers (X-Content-Type-Options, X-Frame-Options, CSP, HSTS)
- Rate limiting on login, API, and status page endpoints
