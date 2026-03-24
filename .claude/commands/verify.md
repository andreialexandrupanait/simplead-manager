---
description: Run the full verification gate (build → lint → static analysis → tests) to validate code before committing
---

Run the following verification steps in order. Stop at the first failure and report the issue.

## Step 1: Build Frontend Assets
```bash
npm run build
```

## Step 2: Lint PHP (Pint)
```bash
./vendor/bin/pint --test
```
If lint fails, run `./vendor/bin/pint` to auto-fix, then report what changed.

## Step 3: Static Analysis (PHPStan)
```bash
./vendor/bin/phpstan analyse
```

## Step 4: Run Tests
```bash
docker compose exec app php artisan test
```

Report results for each step. If all pass, confirm the code is ready to commit.

$ARGUMENTS
