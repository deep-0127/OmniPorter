# 🚀 Unlocking Data Mastery with **OmniPorter**
### *The Ultimate Laravel Import/Export Framework*

Tired of manual data entry nightmares and clunky import/export processes?  
Dream of a world where your application effortlessly ingests and disgorges vast datasets with bulletproof validation and intelligent relationship management?

**Your quest ends here—with OmniPorter.**

OmniPorter is a **meticulously engineered, battle-tested solution** that injects your Eloquent models with unparalleled import and export superpowers.  
Prepare to catapult your application's data fluency into the stratosphere.

This isn't merely about shuffling bits and bytes; it's about **intelligent, resilient, and user-centric data orchestration**.  
OmniPorter harnesses the full might of Laravel's queueing system, trait-based architecture, dynamic class resolution, and advanced caching mechanisms to forge a system that:

- ✅ **Ingests mammoth files asynchronously**, keeping your UI snappy
- ✅ **Validates data with surgical precision**, catching errors before they corrupt your database
- ✅ **Navigates complex model relationships** like a seasoned architect
- ✅ **Ensures peak performance** even under heavy load
- ✅ **Keeps your users fully informed** with real-time notifications

Let’s embark on this journey to bestow your models with these remarkable capabilities—**OmniPorter style**.

---

## 🧱 I. The Foundational Pillars: Architecting OmniPorter’s Excellence

- **🧩 Traits (`HasImport`, `HasExport`)**  
  Your plug-and-play powerups—just `use` them in your Eloquent models and you're off to the races.

- **📜 Interfaces (`Importable`, `Exportable`)**  
  Sacred contracts that ensure any participating model defines the required metadata and methods.

- **🎩 Service Provider (`ImportExportServiceProvider`)**  
  The dynamic scanner and registrar of all eligible models—**auto-wiring FTW**, courtesy of OmniPorter.

- **🧰 Controllers (`ImportController`, `ExportController`)**  
  OmniPorter’s API gatekeepers: enforcing permissions, handling requests, and delegating heavy work to jobs.

- **🧠 Intelligent Caching (`ImportDetailsCache`, `ExportDetailsCache`)**  
  Persist chunked job state in Redis for smooth recovery and flawless continuity.

- **📦 Queues & Jobs**  
  All major processing (reading, saving, exporting) happens off the main thread—**no UI freeze ever**.

- **📬 Notifications**  
  Clear and concise emails are dispatched to notify users of success or failure—with full results.

---

## 📥 II. Making Your Model Importable with OmniPorter

### 🔧 A. Implementing the `Importable` Contract

```php
use App\Concerns\Importable;
use Illuminate\Database\Eloquent\Model;

class YourModel extends Model implements Importable
{
    public static function getUniqueKeyForImportExport(): string
    {
        return 'work_email';
    }

    public static function getListOfRelationDetails(): array
    {
        return [
            Department::class => ['type' => 'belongsTo', 'method' => 'department', 'field' => 'department_id'],
            Employee::class => ['type' => 'belongsTo', 'method' => 'manager', 'field' => 'reports_to'],
            Role::class => ['type' => 'belongsToMany', 'method' => 'roles'],
        ];
    }
}
```

---

### ✨ B. Leveraging the `HasImport` Trait

```php
use App\Traits\HasImport;

class YourModel extends Model implements Importable
{
    use HasImport;
}
```

---

### ✅ C. Model-Specific Validation

```php
// CreateEmployeeRequest
public function rules(): array
{
    return [
        'work_email' => ['required', 'string', 'email', 'max:255', 'unique:employees,work_email'],
        // ...
    ];
}
```

```php
// UpdateEmployeeRequest
public function rules(?int $id = null): array
{
    return [
        'work_email' => ['required', 'string', 'email', 'max:255', Rule::unique('employees', 'work_email')->ignore($id)],
        // ...
    ];
}
```

---

### 🔄 D. Mapping Excel Headings to Model Fields

- Excel headers should match your model’s `fillable` fields.
- For related models, use their `getUniqueKeyForImportExport()` return value as the header name.

---

## 📤 III. Making Your Model Exportable with OmniPorter

### 🧲 A. Use the `HasExport` Trait

```php
use App\Traits\HasExport;

class YourModel extends Model implements Importable
{
    use HasExport;
}
```

---

### 📃 B. Define `getColumnsToExport()`

```php
public static function getColumnsToExport(): array
{
    return [
        'first_name',
        'work_email',
        'department',
        'roles',
    ];
}
```

---

### 🔍 C. Filtering Exports the OmniPorter Way

Example queries:

```
?status=active
?name_like=john
?department_id_in=1,2,3
```

| Suffix   | Description                     |
|----------|---------------------------------|
| `_eq`    | Equal to                        |
| `_ne`    | Not equal to                    |
| `_gt`    | Greater than                    |
| `_gte`   | Greater than or equal to        |
| `_lt`    | Less than                       |
| `_lte`   | Less than or equal to           |
| `_like`  | SQL LIKE (e.g. `%value%`)       |
| `_nlike` | SQL NOT LIKE                    |
| `_in`    | In list (comma-separated)       |
| `_nin`   | Not in list (comma-separated)   |
| `_null`  | Is NULL                         |
| `_nnull` | Is NOT NULL                     |

---

## 🌐 IV. Routing Data Traffic with OmniPorter

### 📥 Import Endpoint

```http
POST /imports/{resource}/{mode}
```

- `mode`: `create` or `update`
- `file`: required in body

---

### 📤 Export Endpoint

```http
GET /exports/{resource}
```

- Fully filterable
- OmniPorter handles everything in the background

---

## 🛡️ V. OmniPorter Authorization & Permissions

- `employee:import`, `employee:create`, `employee:store`
- `employee:update`, `employee:edit`
- `employee:export`, `employee:index`

```php
$user->hasAnyPermission(['employee:create', 'employee:store']);
```

---

## ⚙️ VI. Behind the Curtains of OmniPorter

### 🔍 1. Dynamic Model Discovery

`ImportExportServiceProvider` dynamically discovers models using traits—**no manual registration** needed.

---

### 🌀 2. Asynchronous Processing

- `queueImport()`
- `queueExport()`

Each runs in chunks (default: 250 rows).

---

### 🔄 3. Resilient State via Redis

- Tracks job state
- Recovers from crashes
- Resumes processing seamlessly

---

### 📬 4. Notification System

- `ImportCompleteMail`
- `ExportCompleteMail`

Clear, detailed email reports sent after each job.

---

## 🎯 Conclusion: OmniPorter, Your Data Swiss Army Knife

You now wield **OmniPorter**—a high-performance Laravel-based import/export engine that’s:

✅ Asynchronous  
✅ Resilient  
✅ Secure  
✅ Developer-Friendly

> **Go forth, and automate with elegance—with OmniPorter.**  
> Because your data deserves better.
