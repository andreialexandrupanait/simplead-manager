# Coding Standards

## PHP / Laravel

### General
- PHP 8.2+ with `declare(strict_types=1)` in all files
- PSR-12 coding standard, enforced by Laravel Pint
- Return early to reduce nesting
- Prefer named constants and enums over magic numbers/strings

### Laravel Patterns
- **Controllers**: Thin controllers — delegate to Services or Jobs
- **Services** (`app/Services/`): Business logic layer
- **Jobs** (`app/Jobs/`): Async operations (API calls, backups, emails)
- **Form Requests**: All validation in Form Request classes
- **Eloquent**: Use relationships, scopes, and accessors — avoid raw SQL
- **Config**: Never call `env()` outside `config/` files

### Database (PostgreSQL)
- Use `jsonb` column type (not `json`)
- Use migrations for all schema changes
- Name migrations descriptively: `create_sites_table`, `add_status_to_backups_table`
- Use foreign key constraints with appropriate `onDelete` behavior

### Livewire
- Components in `app/Livewire/` with views in `resources/views/livewire/`
- Keep component state minimal — derive computed values
- Use events for cross-component communication
- Lazy-load heavy components with `#[Lazy]`

## WordPress Connector Plugin

### General
- PHP 7.4+ compatible (no PHP 8.x-only features)
- No `shell_exec`, `exec`, `system`, or `passthru`
- No Composer — standalone plugin with manual requires
- Follow WordPress coding standards for WP-specific code

### Versioning
- Bump both `Version:` header and `SAM_VERSION` constant together
- Use semantic versioning (MAJOR.MINOR.PATCH)

## Frontend

### Tailwind CSS
- Use Tailwind utility classes — avoid custom CSS
- Component-based approach via Blade components
- Dark mode support where applicable

### JavaScript
- Minimal JS — prefer Livewire for interactivity
- Alpine.js for lightweight client-side behavior
- Build with Vite (`npm run build`)

## Git

### Commits
- Use conventional commit prefixes: `feat:`, `fix:`, `refactor:`, `ui:`, `docs:`, `test:`
- Keep commits focused — one logical change per commit
- Write descriptive commit messages explaining "why" not just "what"

### Branches
- `main` is the production branch
- Feature branches: `feature/description`
- Bug fixes: `fix/description`
