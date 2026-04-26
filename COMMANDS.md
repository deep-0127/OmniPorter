# OmniPorter Terminal Commands

This document lists all available terminal commands for OmniPorter with brief descriptions.

## Package Commands

### omniporter:scan

Scans the application for importable/exportable models and updates the discovery cache.

```bash
php artisan omniporter:scan
```

**Description**: Refreshes the model discovery cache after adding new models with `HasImport` or `HasExport` traits. This command scans the configured model paths and updates the cached class maps used by the controllers.

**Use When**: After adding new models that use OmniPorter traits.

**Code Reference**: `src/Import/Console/Commands/ScanImportableModelsCommand.php`

---

### omniporter:import

Run an import from the command line.

```bash
php artisan omniporter:import {model} {file} {mode}
```

**Parameters**:
- `model`: Fully qualified model class name (e.g., `App\Models\Employee`)
- `file`: Path to the Excel/CSV file to import
- `mode`: Import mode - `create` or `update`

**Example**:
```bash
php artisan omniporter:import "App\Models\Employee" storage/imports/employees.xlsx create
```

**Description**: Imports data from an Excel or CSV file into the specified model. The file is processed in chunks and results are logged. Use `create` mode to insert new records or `update` mode to update existing records.

**Use When**: Running imports from cron jobs, scripts, or command line.

**Code Reference**: `src/Import/Console/Commands/ImportCommand.php`

---

### omniporter:export

Run an export from the command line.

```bash
php artisan omniporter:export {model} {output} {--columns=*} {--type=xlsx}
```

**Parameters**:
- `model`: Fully qualified model class name (e.g., `App\Models\Employee`)
- `output`: Output file path for the exported data

**Options**:
- `--columns`: Comma-separated list of columns to export (default: all exportable columns)
- `--type`: Export format - `xlsx` or `csv` (default: `xlsx`)

**Example**:
```bash
php artisan omniporter:export "App\Models\Employee" storage/exports/employees.xlsx --columns=name,email,department_id --type=xlsx
```

**Description**: Exports data from the specified model to an Excel or CSV file. Supports column selection and filtering via query parameters.

**Use When**: Running exports from cron jobs, scripts, or command line.

**Code Reference**: `src/Export/Console/Commands/ExportCommand.php`

---

### omniporter:clear-cache

Clear all OmniPorter cache entries.

```bash
php artisan omniporter:clear-cache
```

**Description**: Removes all cached import/export data from the configured cache store. This includes ImportDetailsCache instances, ExportDetailsCache instances, and any other cached state.

**Use When**: Troubleshooting cache issues, after configuration changes, or to free up memory.

**Code Reference**: `src/Console/Commands/ClearCacheCommand.php`

---

### omniporter:cleanup

Clean up old import batches and files.

```bash
php artisan omniporter:cleanup {--days=7}
```

**Options**:
- `--days`: Delete batches older than N days (default: 7)

**Example**:
```bash
php artisan omniporter:cleanup --days=30
```

**Description**: Removes old import files, result files, and cache entries for batches older than the specified number of days. Helps manage disk space by cleaning up completed imports.

**Use When**: Regular maintenance to clean up old import data.

**Code Reference**: `src/Import/Console/Commands/ImportBatchCleanupCommand.php`

---

### omniporter:scaffold

Generate a model stub with OmniPorter configuration.

```bash
php artisan omniporter:scaffold {name}
```

**Parameters**:
- `name`: Model name (e.g., `Employee`, `Product`)

**Example**:
```bash
php artisan omniporter:scaffold Employee
```

**Description**: Creates a new model file with OmniPorter configuration already set up. The generated model includes the `HasImport` and `HasExport` traits with example configuration for unique keys, relations, validation, and columns.

**Use When**: Creating new models that need import/export functionality.

**Code Reference**: `src/Console/Commands/ScaffoldCommand.php`

---

## Laravel Queue Commands

### Queue Worker

Start the queue worker to process import/export jobs.

```bash
php artisan queue:work
```

**Description**: Starts the Laravel queue worker to process queued import and export jobs. Required for asynchronous processing.

**Use When**: Running imports/exports in production or development with queue-based processing.

**Options**:
- `--queue`: Specify queue name (e.g., `--queue=imports`)
- `--connection`: Specify queue connection (e.g., `--connection=redis`)
- `--tries`: Number of attempts before failing (e.g., `--tries=3`)
- `--timeout`: Maximum runtime per job (e.g., `--timeout=300`)

**Example**:
```bash
php artisan queue:work --queue=imports --tries=3 --timeout=300
```

---

### Queue Listen

Listen to the queue for new jobs (development mode).

```bash
php artisan queue:listen
```

**Description**: Listens to the queue and processes jobs as they arrive. Automatically reloads code changes, making it suitable for development.

**Use When**: Development with frequent code changes.

**Example**:
```bash
php artisan queue:listen --queue=imports
```

---

### Queue Restart

Restart queue workers after deployment.

```bash
php artisan queue:restart
```

**Description**: Signals all queue workers to restart after processing their current job. Required after deploying code changes to ensure workers use the new code.

**Use When**: After deploying new code that affects import/export processing.

---

## Laravel Cache Commands

### Cache Clear

Clear all application cache.

```bash
php artisan cache:clear
```

**Description**: Clears all cached data including OmniPorter's ImportDetailsCache and ExportDetailsCache instances.

**Use When**: Troubleshooting or after configuration changes.

---

### Cache Forget

Forget a specific cache key.

```bash
php artisan cache:forget {key}
```

**Parameters**:
- `key`: Cache key to forget

**Example**:
```bash
php artisan cache:forget omniporter_import_550e8400-e29b-41d4-a716-446655440000
```

**Description**: Removes a specific cache entry by key. Useful for clearing a specific import batch's cache.

**Use When**: Clearing a specific import's cached state.

---

## Laravel Storage Commands

### Storage Link

Create symbolic link for public storage.

```bash
php artisan storage:link
```

**Description**: Creates a symbolic link from `public/storage` to `storage/app/public`. Required if serving import/export files publicly.

**Use When**: Setting up a new application or after storage configuration changes.

---

### Storage List

List files in a storage directory.

```bash
php artisan storage:list {path}
```

**Parameters**:
- `path`: Storage path to list (e.g., `imports`)

**Example**:
```bash
php artisan storage:list imports
```

**Description**: Lists all files in the specified storage directory. Useful for viewing uploaded import files or generated export files.

**Use When**: Debugging file storage issues.

---

## Laravel Config Commands

### Config Clear

Clear configuration cache.

```bash
php artisan config:clear
```

**Description**: Clears the cached configuration file. Required after changing `config/omniporter.php`.

**Use When**: After modifying OmniPorter configuration.

---

### Config Cache

Cache configuration for production.

```bash
php artisan config:cache
```

**Description**: Caches all configuration files for faster loading. Recommended for production.

**Use When**: Deploying to production.

---

## Laravel View Commands

### View Clear

Clear compiled views.

```bash
php artisan view:clear
```

**Description**: Clears all compiled view files. Required after modifying email templates.

**Use When**: After modifying `emails.import_complete` or other email views.

---

## Laravel Route Commands

### Route Clear

Clear route cache.

```bash
php artisan route:clear
```

**Description**: Clears the cached routes. Required after modifying route definitions.

**Use When**: After modifying OmniPorter routes.

---

### Route Cache

Cache routes for production.

```bash
php artisan route:cache
```

**Description**: Caches all routes for faster loading. Recommended for production.

**Use When**: Deploying to production.

---

## Development Commands

### Composer Commands

#### Install Dependencies

```bash
composer install
```

**Description**: Installs all PHP dependencies including OmniPorter.

**Use When**: Setting up a new environment or after `composer.lock` changes.

---

#### Update Dependencies

```bash
composer update
```

**Description**: Updates all PHP dependencies to their latest versions.

**Use When**: Updating OmniPorter or other packages.

---

#### Dump Autoloader

```bash
composer dump-autoload
```

**Description**: Regenerates the autoload files. Required after adding new classes.

**Use When**: After adding new models or classes.

---

### NPM Commands

#### Install Dependencies

```bash
npm install
```

**Description**: Installs all frontend dependencies.

**Use When**: Setting up a new environment.

---

#### Run Development Server

```bash
npm run dev
```

**Description**: Starts the Vite development server for frontend assets.

**Use When**: Developing frontend components.

---

## Testing Commands

### Run Tests

```bash
php artisan test
```

**Description**: Runs all PHPUnit tests.

**Use When**: Running the test suite.

---

### Run Specific Test

```bash
php artisan test --filter {test_name}
```

**Parameters**:
- `test_name`: Name or pattern of test to run

**Example**:
```bash
php artisan test --filter ImportTest
```

**Description**: Runs tests matching the specified filter.

**Use When**: Running specific tests during development.

---

## Database Commands

### Run Migrations

```bash
php artisan migrate
```

**Description**: Runs all pending database migrations.

**Use When**: Setting up a new database or after adding migrations.

---

### Rollback Migrations

```bash
php artisan migrate:rollback
```

**Description**: Rolls back the last batch of migrations.

**Use When**: Reverting database changes.

---

### Fresh Migration

```bash
php artisan migrate:fresh
```

**Description**: Drops all tables and re-runs all migrations.

**Use When**: Resetting the database (WARNING: deletes all data).

---

## Common Workflows

### Initial Setup

```bash
# Install dependencies
composer install
npm install

# Publish config
php artisan vendor:publish --tag=omniporter-config

# Run migrations
php artisan migrate

# Create storage link
php artisan storage:link

# Cache configuration
php artisan config:cache
php artisan route:cache
```

### Development Workflow

```bash
# Terminal 1: Queue worker
php artisan queue:work --queue=imports

# Terminal 2: Development server
php artisan serve

# Terminal 3: Vite (if using frontend)
npm run dev
```

### Production Deployment

```bash
# Install dependencies
composer install --optimize-autoloader --no-dev
npm ci

# Clear and cache
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

# Restart queue workers
php artisan queue:restart

# Run migrations
php artisan migrate --force
```

### Troubleshooting

```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Clear OmniPorter cache
php artisan omniporter:clear-cache

# Restart queue
php artisan queue:restart

# Check queue status
php artisan queue:failed
```

### Maintenance

```bash
# Clean up old imports (older than 30 days)
php artisan omniporter:cleanup --days=30

# Scan for new models
php artisan omniporter:scan

# Clear cache
php artisan omniporter:clear-cache
```
