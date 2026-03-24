# Laravel Conventions

- Use strict types in all PHP files: `declare(strict_types=1);`
- Follow PSR-12 coding standard, enforced by Laravel Pint
- Use Form Requests for validation, not inline rules in controllers
- Use Eloquent relationships and scopes — avoid raw queries
- Never call `env()` outside config files — use `config()` instead
- Queue heavy operations (API calls, emails, file processing) as Jobs
- Database: PostgreSQL — use `jsonb` (not `json`), use appropriate types
- Use Laravel's built-in helpers over PHP native when available
- Livewire components handle UI state; Services handle business logic
- Return early from methods to reduce nesting
- Use PHP enums (in `app/Enums/`) for fixed value sets
