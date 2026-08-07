<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## BLUE Test Database (`blue_test_db`)

`php artisan test` runs exclusively against a dedicated `blue_test_db` MySQL database (enforced by `phpunit.xml`'s `DB_DATABASE` override and by a safety guard in `tests/TestCase.php` that aborts immediately if the active database is not exactly `blue_test_db`). It is never run against `blue_db`.

To provision (or re-sync) `blue_test_db` on a fresh clone, load the schema and reference/seed data directly from the repository's authoritative files — `../database/blue_v1_schema.sql` and `../database/blue_v1_seed.sql` — using the artisan command below. It writes nothing to disk (no copied `.sql` files) and resolves DB credentials at runtime from whatever connection env vars are active, so nothing is embedded in the command itself. The command refuses to run against any database other than `blue_test_db`.

**One-time schema setup requires DDL-capable credentials.** The application's runtime user (`blue_app`) is intentionally least-privilege (`SELECT, INSERT, UPDATE, DELETE` only — no `CREATE`/`DROP`), so applying `blue_v1_schema.sql` (which drops/recreates tables) needs an admin/DDL-capable MySQL user for this one step. Reference-data sync from `blue_v1_seed.sql` is DML-only (`INSERT ... ON DUPLICATE KEY UPDATE`) and works fine with `blue_app`.

```bash
# 1. Create the database once, with an admin/DDL-capable user:
mysql -h 127.0.0.1 -u root -p -e "CREATE DATABASE IF NOT EXISTS blue_test_db CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

# 2. Apply schema + reference data, using DDL-capable credentials for this run:
DB_DATABASE=blue_test_db DB_USERNAME=root DB_PASSWORD=*** php artisan blue:provision-test-db
```

After the initial setup, reference data can be re-synced at any time with the normal least-privilege app credentials:

```bash
DB_DATABASE=blue_test_db php artisan blue:provision-test-db
```

Re-running is safe either way: `blue_v1_schema.sql` drops/recreates tables and `blue_v1_seed.sql` upserts reference rows (`ON DUPLICATE KEY UPDATE`) — it never touches customer/booking data because the seed file contains none.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
