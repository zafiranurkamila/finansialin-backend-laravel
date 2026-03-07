# Finansialin Backend (Laravel)

REST API backend for **Finansialin**, built with Laravel. This service handles authentication, user profile management, categories, transactions, budgets, notifications, email verification, and password reset.

## Repository Description (for GitHub "About")

Laravel REST API for Finansialin personal finance app, featuring token-based auth, budgeting, transactions, notifications, email verification, and password reset.

## Main Features

- Token-based authentication (access + refresh token)
- User profile and password management
- Category and transaction management
- Budget planning, usage tracking, and goals
- Notification system
- Email verification flow
- Forgot/reset password flow
- Feature tests for migration parity and frontend contract

## Tech Stack

- PHP 8.x
- Laravel 12
- Eloquent ORM
- PHPUnit

## Project Structure

- `app/Http/Controllers` : API controllers
- `app/Models` : Eloquent models
- `routes/api.php` : API routes
- `database/migrations` : database schema migrations
- `tests/Feature` : API feature tests

## Quick Start

1. Install dependencies:

```bash
composer install
```

2. Copy env file:

```bash
cp .env.example .env
```

3. Generate app key:

```bash
php artisan key:generate
```

4. Run migrations:

```bash
php artisan migrate
```

5. Run server:

```bash
php artisan serve --host=127.0.0.1 --port=3000
```

## Windows Note (PHPRC)

If PHP CLI extensions are not detected (example: `mbstring`), run commands with:

```bash
set "PHPRC=<project-root>" && php artisan <command>
```

Example:

```bash
set "PHPRC=c:\Users\zafir\Downloads\Semester 5\ippl\project" && php artisan test
```

## Important Auth Endpoints

- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/refresh`
- `POST /api/auth/logout`
- `GET /api/auth/profile`
- `POST /api/auth/email/verification-notification`
- `POST /api/auth/verify-email`
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`

## Testing

Run all tests:

```bash
php artisan test
```

Run specific suites:

```bash
php artisan test --filter=FrontendContractTest
php artisan test --filter=MigrationParityTest
```

## License

This project is for educational/development use under your repository policy.
