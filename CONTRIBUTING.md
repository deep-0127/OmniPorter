# Contributing to OmniPorter

Thank you for taking the time to contribute! 🎉

This document describes the development process, coding standards, and how to submit changes.

---

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Running Tests](#running-tests)
- [Coding Standards](#coding-standards)
- [Submitting a Pull Request](#submitting-a-pull-request)
- [Reporting Bugs](#reporting-bugs)
- [Requesting Features](#requesting-features)

---

## Code of Conduct

Please be respectful and constructive in all interactions. We follow the [Contributor Covenant](https://www.contributor-covenant.org/version/2/1/code_of_conduct/) Code of Conduct.

---

## Getting Started

1. **Fork** the repository on GitHub.
2. **Clone** your fork:
   ```bash
   git clone https://github.com/<your-username>/omniporter.git
   cd omniporter
   ```
3. **Install dependencies**:
   ```bash
   composer install
   ```
4. **Copy** the environment file and generate an app key:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

---

## Development Setup

OmniPorter's test suite uses:

| Concern    | Driver                  |
|------------|-------------------------|
| Database   | SQLite `:memory:`       |
| Cache      | `array` driver          |
| Queue      | `sync`                  |
| Mail       | `array` driver / `Mail::fake()` |
| Redis      | Facade mock (no real Redis needed) |

All settings are pre-configured in `phpunit.xml`. You do **not** need a running Redis or MySQL instance to run the tests.

---

## Running Tests

```bash
# Run the full suite
php artisan test

# Run only unit tests
php artisan test --testsuite=Unit

# Run only feature/integration tests
php artisan test --testsuite=Feature

# Run with verbose output
php artisan test --verbose

# Run a single test class
php artisan test tests/Feature/GenericImportIntegrationTest.php
```

### Writing Tests

- **Unit tests** live in `tests/Unit/` and test isolated classes.
- **Feature / integration tests** live in `tests/Feature/` and test full request-response or pipeline cycles.
- Row-level import tests should use the `FakeExcelRow` helper (defined in `GenericImportIntegrationTest.php`) rather than real spreadsheet files.
- Do **not** use `RefreshDatabase` in tests that exercise `GenericImport::onRow()`, because `onRow()` opens its own `DB::beginTransaction()`. Use manual `Schema::create` / `truncate` instead.

---

## Coding Standards

- **PHP 8.2+** — use typed properties, `enum`, `readonly`, and `match` where appropriate.
- **`declare(strict_types=1)`** — required in every PHP file.
- **PSR-4 autoloading** — `src/` maps to `OmniPorter\`, `app/` maps to `App\`.
- **Laravel Pint** — the project ships with Pint for code style enforcement:
  ```bash
  ./vendor/bin/pint
  ```
- **No `var_dump` / `dd` / `dump`** in committed code.
- **Docblocks** — public API methods must have PHPDoc `@param` / `@return` / `@throws` annotations.

---

## Submitting a Pull Request

1. Create a feature branch from `main`:
   ```bash
   git checkout -b feat/my-new-feature
   ```
2. Write your code and tests. Ensure all tests pass:
   ```bash
   php artisan test
   ```
3. Run Pint:
   ```bash
   ./vendor/bin/pint
   ```
4. Update `CHANGELOG.md` under `[Unreleased]`.
5. Push and open a PR against `main`.

### PR Checklist

- [ ] All existing tests pass
- [ ] New functionality is covered by tests
- [ ] `CHANGELOG.md` updated
- [ ] Code passes Pint (no style violations)
- [ ] Docblocks added for new public methods

---

## Reporting Bugs

Please open a [GitHub Issue](https://github.com/deep-shah/omniporter/issues) with:

- A minimal reproduction case (ideally a failing test)
- Your Laravel version, PHP version, and OmniPorter version
- The full exception message and stack trace

---

## Requesting Features

Open a [GitHub Discussion](https://github.com/deep-shah/omniporter/discussions) or Issue describing:

- The problem you are trying to solve
- Your proposed API / interface
- Any alternative approaches you considered

We prefer small, focused PRs over large, sweeping changes. When in doubt, open an issue first!
