# Project Management — Implementation Plan

**Date:** 2026-08-09
**Scope:** Project CRUD (list, create, edit, status updates, soft delete/restore) for Staff/Admin and Project Managers, via Livewire UI and a versioned JSON API.

---

## 1. Current State (facts, verified in the codebase — **as of 2026-08-10, all phases complete**)

This section is a snapshot, not a decision record: it describes what is on disk today and is refreshed as phases land. Everything below §2 is the plan itself and stays as agreed.

**Platform**

- Laravel 13 (`laravel/framework` 13.24.0), PHP 8.5.8, Livewire 4 (4.3.5) starter kit with Flux UI 2 (2.15.1).
- Auth is handled by **Fortify** (session-based web auth, 2FA, passkeys). **Sanctum** 4.3.3 guards the API (phase 7).
- **spatie/laravel-permission** 8.3.0. Permissions live on roles only; the Super Admin role bypasses all gates via `Gate::before` (check with `can()`, never `hasPermissionTo()` — see `.ai/rules/app.md`).
- Livewire pages are single-file components in `resources/views/pages/` (`⚡name.blade.php`), routed with `Route::livewire('path', 'pages::name')`.
- Testing: Pest 5 (`php artisan test --compact`), Pint, Larastan level 7. **284 tests, 797 assertions, all passing.**

**Permissions and roles** (phase 0)

- `RolePermissionSeeder::PERMISSIONS` holds the six project slugs — `projects.view`, `projects.create`, `projects.update-status`, `projects.edit-details`, `projects.delete`, `projects.restore` — alongside `roles.*` and `users.*` slugs seeded for future modules that have no feature behind them yet.
- A **Project Manager** role is seeded with the six project permissions as first-run defaults, applied only on creation and never re-synced (§2.14).
- The Super Admin role and account carry `is_protected = true` (`roles.is_protected`, `users.is_protected`) — protected records can **never be deleted, not even by a Super Admin**.

**Built (phases 1–8)**

- `clients` and `projects` tables, with `name_key`, `version`, soft deletes and the composite unique on (`client_id`, `name`); `Client` / `Project` models, factories, `ProjectSeeder`.
- `App\Enums\ProjectStatus`, `App\Enums\ProjectPriority`; `App\Validation\ProjectRules`; `App\Services\ProjectService`; `App\Exceptions\StaleProjectException`.
- `App\Livewire\Forms\ProjectForm` and `ProjectStatusForm`.
- Pages under `resources/views/pages/projects/` — `⚡index`, `⚡create`, `⚡edit`, `⚡status-modal`; the delete confirmation modal lives in `⚡index`. Routed in `routes/web.php` under `['auth', 'verified']` with `can:` middleware per page.
- API scaffolding: `config/sanctum.php`, the `personal_access_tokens` migration, `HasApiTokens` on `User`, `throttleApi()` in `bootstrap/app.php` and `RateLimiter::for('api')` in `AppServiceProvider`.
- The `/api/v1` route group in `routes/api.php` — all seven endpoints from §5.2, each behind `auth:sanctum` and its `can:` middleware — with `App\Http\Controllers\Api\V1\*`, the Form Requests under `App\Http\Requests\Api\V1\*` (a shared `ProjectRequest` base plus four rules-only subclasses), and `ProjectResource`. `install:api`'s scaffolded `GET /api/user` is still there alongside them.
- `Project::listing()`, the query scope the index page and the API list endpoint share (§4.3).
- `StaleProjectException` → 409 and route-model-binding misses → a `404` that does not name the model class, both registered in `bootstrap/app.php` and scoped to `api/*`.

**Built alongside the plan, not in it**

- `php artisan api:token {email}` (`App\Console\Commands\IssueApiTokenCommand`) and `DemoUserSeeder`, which seeds a Project Manager account outside production — between them, a way to exercise the API as a user whose permissions actually decide something.
- `README.md` (setup, running, technology choices, assumptions) and `docs/api.md` (the endpoint-by-endpoint reference, captured from a running instance).

**Not built**

- Docker and deployment: bonus items in the brief, deliberately left out rather than shipped untested.
- Role and user management screens. The `roles.*` and `users.*` permission slugs are seeded ahead of them (§8.1.7), and the protected-record rule in §2.5 applies whenever they are built.

## 2. Decisions Already Made (confirmed with the product owner)

1. **Sanctum is approved** as the API auth layer (see §5.1 for reasoning).
2. **`projects.update-status-priority` was renamed to `projects.update-status`.** Priority changes belong to the edit form and are covered by `projects.edit-details`. The status modal handles only status + timestamps.
3. **`projects.restore` exists as its own permission** (not folded into `projects.delete`).
4. **The Project Manager role is seeded** with the six project permissions as its starting set (listed explicitly in the seeder, not derived from the `projects.` prefix — see item 14 for why they are defaults rather than policy).
5. **Protected records:** the Super Admin role and the Super Admin account cannot be deleted by anyone. Enforced by the `is_protected` flag; any future role/user delete feature must refuse when `is_protected` is true — this is a hard rule, with no bypass for Super Admins.

Added after plan review (2026-08-09):

6. **Project names are unique *per client*,** not globally — two clients may each have a "Website Redesign" (§3.2, §4.2).
7. **Validation lives in one class, `ProjectRules`,** consumed by both the Livewire form objects and the API Form Requests, so no rule is written twice (§4.2).
8. **Authorization is enforced server-side and mirrored in the UI** — `@can` hides controls, `Gate::authorize` and `can:` middleware are what actually refuse the write (§4.2.1).
9. **Sort and direction are whitelisted** on both surfaces from a shared constant (§4.3).
10. **Client resolution is race-safe and case-insensitive** (§4.1), and the index eager-loads `client` (§4.3).
11. **Optimistic locking is in scope** — a `version` column, a toast in the UI, a 409 on the API (§8.0).
12. **Rate limiting is enabled via `throttleApi()` in `bootstrap/app.php`** (60/min keyed on the user), *not* by listing `'api'` or `'throttle:api'` on the route group — the `api` group is applied automatically and carries no limiter by default (§5.2). JSON error rendering is already handled by the existing `shouldRenderJsonWhen` config, so no middleware is added for it. **Sanctum token abilities are deferred**, so tokens are full-privilege for their owner — stated explicitly in §5.1 rather than left implicit.
13. **All `#[Url]`-bound listing state is validated component-side** with the same `ProjectRules::listing()` rules, not just `sort` (§4.3).
14. **Seeded role permissions are first-run defaults, not fixed policy.** Changing a seeded role's permissions at runtime is a valid administrative action, so the seeder applies `PROJECT_MANAGER_PERMISSIONS` only when it creates the role (`wasRecentlyCreated`) and never re-syncs an existing one — re-running `db:seed` on a live database must not revoke an admin's change. The deployment documentation will describe what the seeded data is and that it is a starting point. Consequently, tests assert that seeding *works*, never which grants a role holds (§9).

---

## 3. Data Model

### 3.1 `clients` table

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `name` | string | Display name, stored as the user typed it (first spelling wins) |
| `name_key` | string, **unique** | Canonical match key: `Str::lower()` of the trimmed, whitespace-collapsed name. All lookups and the uniqueness guarantee run through this column. |
| timestamps | | |

**Why a separate `name_key` column** (see §4.1 for the race it closes): a unique index on `name` alone is case-*sensitive* on SQLite and PostgreSQL, so "Acme Corp" and "acme corp" would both insert and become two clients. Normalizing in PHP before the query doesn't fix it either — under concurrency the database is the only arbiter. A canonical key column makes the unique index authoritative on every driver, while `name` stays human-readable for display.

### 3.2 `projects` table

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `client_id` | FK → `clients` | `restrictOnDelete` |
| `name` | string | Required; **unique per client** — composite unique index on (`client_id`, `name`). Two different clients may have a project with the same name. |
| `description` | `text`, nullable | Per spec |
| `status` | string, nullable | Backed by `ProjectStatus` enum |
| `priority` | string, nullable | Backed by `ProjectPriority` enum |
| `start_date` | date | Required, defaults to today in the form (not in the DB) |
| `due_date` | date, nullable | Must be `>= start_date` |
| `started_at` | datetime, nullable | Set when status → In Progress |
| `completed_at` | datetime, nullable | Set when status → Completed; must be `>= started_at` |
| `version` | unsigned integer, default 1 | Optimistic-locking counter — see §8.0 |
| `deleted_at` | softDeletes | |
| `deleted_by` | FK → `users`, nullable | `nullOnDelete`; who soft-deleted it |
| timestamps | | |

Indexes: composite unique on (`client_id`, `name`); plus `status`, `priority`, `due_date` (filter/sort targets — every column in `ProjectRules::SORTABLE` that isn't already indexed).

### 3.3 Enums (`app/Enums/`)

```php
enum ProjectStatus: string
{
    case Planning = 'planning';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
}

enum ProjectPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
```

String-backed enums cast on the model; validation uses `Rule::enum(...)`, so "validate from Enum" is enforced by the framework rather than hand-rolled `in:` lists. Each enum gets a `label(): string` method for display text ("In Progress", "On hold").

### 3.4 Models

- `Client` — `HasMany projects`, factory + seeder.
- `Project` — `SoftDeletes`, `BelongsTo client`, `BelongsTo deletedBy (User)`, enum casts, date casts, factory (with states per status) + seeder.

---

## 4. Architecture

```
Livewire page/component ──> ProjectForm (validation + authorization)
                                 │
                                 ▼
API controller ─ FormRequest ─> ProjectService (business logic) ─> Eloquent
```

Both validation entry points delegate their rules to one place:

```
ProjectForm (Livewire) ──┐
                         ├──> ProjectRules::details()/status()/listing()  ← single source of truth
StoreProjectRequest  ────┘        + ::messages() + ::attributes() + ::SORTABLE
```

### 4.1 `App\Services\ProjectService`

Single home for business logic, shared by the web UI and the API:

- `create(array $data): Project` — resolves the client (see *Client resolution* below), then creates the project. Wrapped in a DB transaction because it writes two tables.
- `updateDetails(Project $project, array $data, int $expectedVersion): Project` — everything except status (includes priority, per decision 2). Throws `StaleProjectException` if the row moved on (§8.0).
- `updateStatus(Project $project, ProjectStatus $status, ?CarbonImmutable $timestamp, int $expectedVersion): Project` — sets `started_at` when moving to In Progress and `completed_at` when moving to Completed, using the user-supplied datetime (default now). Same staleness guard.
- `delete(Project $project, User $deletedBy): void` — sets `deleted_by`, then soft-deletes.
- `restore(Project $project): Project` — restores and clears `deleted_by`.

The service receives **already-validated** data. Cross-field date rules (`due_date >= start_date`, `completed_at >= started_at`) are validation-layer rules, expressed once and reused (see §4.2/§6).

**Why a service class:** requested by spec ("separation of concerns"), and genuinely earns its keep here — the same create/update/status/delete logic must run from both Livewire and API controllers, so it prevents duplication. It stays a plain class: no interface, no repository layer.

#### Client resolution (race-safe, case-insensitive)

`firstOrCreate()` is **not** atomic — it runs a `SELECT` then an `INSERT`. Two requests creating the same new client name concurrently both miss the select and both insert; the second hits the unique index and throws. Wrapping it in a transaction does not help: the conflict is between transactions, not inside one. So the service resolves clients like this:

Canonicalization has exactly **one** definition, on the model that owns the column — the service and the validation layer (§4.2) both need to turn a typed name into a `name_key`, and two copies of that expression would eventually disagree about whitespace or casing and silently stop matching:

```php
// App\Models\Client
public static function keyFor(string $name): string
{
    return Str::lower(Str::squish($name));
}
```

```php
// App\Services\ProjectService
private function resolveClient(string $name): Client
{
    $attributes = ['name_key' => Client::keyFor($name)];

    try {
        return Client::firstOrCreate($attributes, ['name' => Str::squish($name)]);
    } catch (UniqueConstraintViolationException) {
        // Another request created it between our select and insert.
        return Client::where($attributes)->sole();
    }
}
```

The loser of the race re-fetches the winner's row and continues normally — the user sees a successful save, not an error. `Str::squish()` trims and collapses internal whitespace; lowercasing handles the casing, so "Acme Corp" and "  acme   corp " resolve to one client. Every other caller uses `Client::keyFor()` rather than repeating the two `Str` calls.

**Why catch rather than lock:** a table-level lock or `SELECT … FOR UPDATE` on a row that doesn't exist yet would serialize every project creation to protect against a rare collision. Catching the constraint violation lets the database do the work it already does and costs nothing on the common path. This is the standard Laravel idiom for the pattern.

### 4.2 `App\Validation\ProjectRules` — one definition, two surfaces

Livewire form objects and API Form Requests would otherwise carry the same rules twice and drift apart the first time one is edited. Both surfaces instead call a single plain class that owns every rule, message, and attribute name:

```php
final class ProjectRules
{
    /** Whitelisted sort columns — see §4.3. */
    public const SORTABLE = ['name', 'due_date', 'status', 'priority', 'created_at'];

    /** Create + edit. Pass the current project on edit so it ignores itself. */
    public static function details(?string $clientName, ?Project $project = null): array;

    /** Status modal / PATCH status endpoint. */
    public static function status(Project $project): array;

    /** Index query params: search, filters, sort, direction, with_deleted. */
    public static function listing(): array;

    /** Shared, user-friendly override messages. */
    public static function messages(): array;

    /** Field labels, so messages read "client name" not "client_name". */
    public static function attributes(): array;
}
```

Consumers stay one line each:

| Surface | Usage |
| --- | --- |
| `ProjectForm` (Livewire) | `rules()` returns `ProjectRules::details($this->client_name, $this->project)`; `messages()` and `validationAttributes()` delegate likewise |
| `StoreProjectRequest` / `UpdateProjectRequest` | `rules()` returns `ProjectRules::details($this->input('client_name'), $this->route('project'))` |
| `ProjectStatusForm` / `UpdateProjectStatusRequest` | `ProjectRules::status($project)` |
| Index component / `IndexProjectRequest` | `ProjectRules::listing()` |

**Why a static rules class rather than sharing the form object itself:** Livewire form objects extend `Livewire\Form` and are bound to a component lifecycle; Form Requests extend `FormRequest` and are bound to the HTTP container. Neither can be the other's parent. A dependency-free class both can call is the only place the rules can live *once*. It stays static and stateless — no container binding, no interface, nothing to mock.

**Per-client name uniqueness.** The composite index on (`client_id`, `name`) is expressed in validation as:

```php
$clientId = Client::where('name_key', Client::keyFor($clientName))->value('id');

Rule::unique('projects', 'name')
    ->where('client_id', $clientId)   // scalar form, not a closure — see below
    ->ignore($project)
```

`$clientId` is resolved through the same `Client::keyFor()` used by the service (§4.1). When the name belongs to a **new** client, `$clientId` is `null`, the scoped query matches no rows, and the rule passes — correct, since a brand-new client cannot yet own a conflicting project.

> **Use the scalar `->where('client_id', $clientId)`, never the closure form.** `->where(fn ($q) => …)` stores a closure on the rule object, and that breaks the parity test in §9 both ways: compared as objects, two closures are never equal, so the assertion fails; compared as strings, `Unique::__toString()` omits closure-based wheres entirely, so the scoped clause vanishes and the test passes **vacuously** — green while asserting nothing. The scalar form serializes into the rule string, so the two surfaces are compared on a value that actually includes the scope.

> **Deliberate:** `Rule::unique` queries the table directly, so it also sees **soft-deleted** projects. That is the behavior we want — if a trashed project holds the name and someone restores it, allowing a live duplicate would corrupt the index. The message tells the user the name is taken by a deleted project so they can restore it instead.

### 4.2.1 Authorization — enforced server-side, mirrored in the UI

Authorization runs in **two places, for two different reasons**:

| Layer | Mechanism | Purpose |
| --- | --- | --- |
| **Backend (authoritative)** | `Gate::authorize('projects.create')` at the top of every form object action and service entry point, plus `can:` middleware on routes | The actual security boundary. Every write is refused here regardless of what the client sent. |
| **Frontend (presentational)** | `@can` around buttons, menu items, and table actions | Hides controls the user cannot use. **Convenience only** — never the enforcement. |

The frontend check is not a substitute for the backend one. A Livewire action can be invoked directly from the browser regardless of whether its button was rendered, so hiding a control prevents an unhelpful UI, not an unauthorized write. Both layers read the same permission slug, so they can never disagree about intent.

Per-action mapping:

- `ProjectForm::store()` → `projects.create`; `ProjectForm::update()` → `projects.edit-details`
- `ProjectStatusForm::save()` → `projects.update-status`
- Delete action → `projects.delete`; Restore action → `projects.restore`

Gate abilities **are** the permission names (project convention — no policies), so authorization is `Gate::authorize('projects.create')` etc. Coverage is asserted by tests that call each action as a user lacking the permission and expect a 403.

### 4.3 Livewire UI (Flux components)

Files under `resources/views/pages/projects/`:

| Component | Responsibility |
| --- | --- |
| `⚡index.blade.php` | Paginated list. `wire:model.live.debounce` search (matches project name, client name, description), status + priority filters (`flux:select`), sortable name / due-date columns, "include deleted" `flux:checkbox`. Deleted rows show a **Restore** action (gated by `projects.restore`). Query is `Project::with('client')` — see *Eager loading* below. |
| `⚡create.blade.php` / `⚡edit.blade.php` | Forms using `ProjectForm`. Client name field is a searchable `flux:autocomplete` over existing clients that also accepts free text (new client created on save). Edit never shows status. |
| `⚡status-modal.blade.php` | Confirmation modal (`flux:modal`) for status changes; shows a `flux:date-picker`/time input defaulting to now when moving to In Progress or Completed. |
| Delete confirmation | `flux:modal` on the index page; on confirm calls `ProjectService::delete()`. |

Routes in `routes/web.php` under `['auth', 'verified']`, e.g. `Route::livewire('projects', 'pages::projects.index')->name('projects.index')` (+ create/edit). Page-level access gated with `can:projects.view` middleware; individual buttons/actions wrapped in `@can` (presentational — see §4.2.1).

Default listing scope: **active (non-deleted) projects only**; the checkbox switches the query to `withTrashed()`.

#### Sorting — whitelist required on both surfaces

Sort input reaches `orderBy()`, which does **not** bind identifiers — a column name is interpolated into SQL, so unvalidated input there is an injection vector, and it is the same vector on the Livewire index and the API list endpoint. Both validate against the same constant:

```php
'sort'      => ['nullable', Rule::in(ProjectRules::SORTABLE)],   // name, due_date, status, priority, created_at
'direction' => ['nullable', Rule::in(['asc', 'desc'])],
```

- **API:** `IndexProjectRequest` validates the query string; anything else is a 422.
- **Livewire:** the component's `sortBy(string $column)` action rejects any column not in `ProjectRules::SORTABLE` and falls back to the default sort rather than throwing — a tampered request quietly renders the default list instead of erroring at the user.
- Defaults when absent: `sort = created_at`, `direction = desc`.

Because `SORTABLE` is a single constant in `ProjectRules`, adding a sortable column enables it in both places at once and cannot enable one without the other.

#### Validating the listing state component-side

`sort` is not the only tamperable input. The index component's filter properties are `#[Url]`-bound so the list is shareable and survives a refresh — which means `status`, `priority`, `per_page`, `direction` and `with_deleted` all arrive from the query string and are equally user-controlled. They must not be trusted just because a `flux:select` normally supplies them.

The component runs the **same** `ProjectRules::listing()` rules over its own state before building the query:

```php
public function updated(string $property): void
{
    $rules = ProjectRules::listing();

    if (! array_key_exists($property, $rules)) {
        return;
    }

    try {
        $this->validateOnly($property, $rules);
    } catch (ValidationException) {
        $this->reset($property);
    }
}
```

with a `mount()`-time pass over the whole set to catch values that arrive in the initial URL rather than through an update. Because these are navigational state rather than a form the user is filling in, an invalid value **resets that property to its default** instead of rendering an error — a tampered or stale bookmarked URL shows a sensible list rather than a validation message the user can't act on.

The `catch` is what implements that sentence: `validateOnly()` **throws** `ValidationException` on failure, which Livewire renders as a field error. Without catching it, the code would do the opposite of the reset-to-default behavior described above — which is why it's spelled out here rather than left as "validate it." `per_page` is additionally clamped by the same `between:1,100` rule the API uses, so neither surface can be pushed into rendering the whole table.

#### Eager loading

The list renders `client.name` on every row, so `Project::with('client')` is applied in the shared query builder used by the index component *and* the API list endpoint. Without it each row triggers its own client query — a 25-row page becomes 26 queries. Larastan does not detect N+1, so this is covered by a test asserting the index page's query count stays constant as the row count grows (`DB::listen` counting queries for 1 project vs 20).

### 4.4 Error handling (UI must not break)

- Validation errors: rendered inline by Livewire/Flux — never exceptions.
- Business/infrastructure failures inside the service (e.g. DB error): Livewire actions catch `Throwable` around the service call, log the real exception (`report($e)`), and show a generic `flux:toast` — "Something went wrong. Please try again." Raw exception messages (SQL, stack traces) are never shown to users.
- Authorization is enforced **server-side** (`Gate::authorize` in every form object action, `can:` middleware on every route) and *additionally* mirrored in the UI with `@can` so unusable controls are not rendered — see §4.2.1. The UI check is cosmetic; removing it would degrade UX, not security. A denied action surfaces as a 403 page (page-level) or a toast (inline action), never a raw exception.
- Client-name races and other recoverable conflicts are absorbed by the service (§4.1) and never reach the user as an error.

---

## 5. API

### 5.1 Auth: **Laravel Sanctum** (recommended — approved)

Reasons:

1. **Fits the stated need** — "in case a new frontend language will be integrated." Sanctum covers both futures: SPA cookie-based auth (same-domain JS frontend) and personal access tokens (mobile app, third-party frontend, or separate domain).
2. **First-party and lightweight** — maintained with the framework, installed via `php artisan install:api` (which also scaffolds `routes/api.php`). No OAuth2 server complexity.
3. **Passport is overkill** — OAuth2 flows (authorization codes, third-party clients) are not required. JWT packages add a third-party dependency and manual token lifecycle for no benefit here.
4. **Coexists with Fortify** — Fortify keeps handling web session auth; Sanctum guards `routes/api.php` with `auth:sanctum`. They are designed to be used together.

#### Token abilities — **deferred, and the consequence is explicit**

`can:` middleware checks the *user's* permissions, and a Sanctum token acts as that user. So a token issued for a read-only reporting integration would still pass `can:projects.create` if its owner is a Project Manager: **every token is full-privilege for whatever its owner can do.** Nothing in the `can:` layer constrains it.

Tokens are created with `createToken('name', ['*'])` for now, and scoping is deferred, because no second consumer exists yet — the abilities you'd choose without a real integration to model would be guesses. The cost of that decision is stated here so it isn't discovered later.

**When the first integration lands**, add abilities (`projects:read`, `projects:write`) and check them **alongside**, never instead of, the gate:

```php
Route::middleware(['auth:sanctum', 'abilities:projects:read', 'can:projects.view'])
```

The two answer different questions — the gate asks "may this *user* do it?", the ability asks "may this *token* do it?" — and a token must pass both. Until then, treat every issued token as equivalent to the owner's password, and issue them per-integration so one can be revoked without affecting the others.

### 5.2 Endpoints (`routes/api.php`, versioned)

All under `/api/v1`, `auth:sanctum`, and per-route permission middleware (`can:projects.view`, etc. — works because gate abilities are the permission names, and Super Admin passes via `Gate::before`).

The route group carries only what the framework isn't already applying:

```php
Route::prefix('v1')->middleware(['auth:sanctum'])
```

Getting this right requires knowing exactly what `install:api` does and doesn't set up — verified against the installed framework rather than assumed:

**Don't list `'api'` in the group.** `install:api` adds `api: __DIR__.'/../routes/api.php'` to `withRouting()` in `bootstrap/app.php` (that is the *only* thing it changes), and that already wraps every route in the file in the `api` middleware group. Naming `'api'` again in the route group would run the whole group twice — `SubstituteBindings` included.

**But the `api` group does not throttle by default.** In `Middleware::getMiddlewareGroups()` the group is built as `array_filter([... $this->apiLimiter ? 'throttle:'.$this->apiLimiter : null, SubstituteBindings::class])`, and `$apiLimiter` has no default value — it is null unless `throttleApi()` is called. So on a stock Laravel 11+ install the `api` group is `[SubstituteBindings]` and nothing more. **Dropping `'throttle:api'` from the route group without enabling it elsewhere would leave the API completely unthrottled** — the opposite of the intent. Defining `RateLimiter::for('api', …)` alone does *not* apply it; a named limiter is inert until some `throttle:` middleware references it.

Enable it in the group where it belongs, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->throttleApi();   // adds 'throttle:api' to the api group
})
```

with the limiter itself defined in `AppServiceProvider`:

```php
RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
    ->by($request->user()?->id ?: $request->ip()));
```

**The two ship together or the API is broken.** The dependency runs both ways, and the second direction is the harsher one: `throttleApi()` only writes the *string* `throttle:api` onto the group, so without a matching `RateLimiter::for('api', …)` the middleware finds no named limiter, falls through to treating `"api"` as a max-attempts value, and throws `MissingRateLimiterException` — a 500 on every authenticated API request (`ThrottleRequests::resolveMaxAttempts()`, lines 207–211). Nothing in the framework supplies that limiter: the 60/min everyone remembers lived in `RouteServiceProvider::configureRateLimiting()`, which the skeleton dropped in Laravel 11 and never replaced, and `grep` over `illuminate/` finds no `RateLimiter::for(` at all. Verified on 13.24.0 — `app(RateLimiter::class)->limiter('api')` returns `null` on a stock install. This is why the limiter landed in phase 7 alongside `throttleApi()` rather than in phase 8 (§9).

A guest request will *not* reveal this: `Authenticate` sorts ahead of `ThrottleRequests` in the framework's middleware priority list, so an unauthenticated call is refused with a clean 401 before the throttle is ever reached. The 500 only appears once a request authenticates — which is also why the guest-side IP bucket is asserted against the limiter closure rather than through a route (§9).

**60 requests/minute, keyed on the user.** Keying on the user rather than the IP matters: several staff behind one office NAT would otherwise share a single bucket and throttle each other. The IP fallback keeps unauthenticated requests from being used to probe. A limit isn't a nice-to-have here — token auth means a leaked token can be replayed as fast as the network allows, and every other surface in this plan (sort whitelist, page-size cap, authorization) is already accounted for; an unbounded request rate would be the one gap left. 60/min sits well above human-driven UI use while capping automated abuse; revise once a real integration's traffic shape is known.

**No `ForceJsonResponse` middleware.** An earlier draft of this plan proposed one. It is unnecessary — `bootstrap/app.php` already carries:

```php
$exceptions->shouldRenderJsonWhen(
    fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
);
```

Every route here is under `/api/v1`, so `is('api/*')` matches and the framework already renders 401/403/404/422/429 as JSON regardless of the `Accept` header. Adding a middleware to force the same outcome would be a second mechanism for a solved problem. The behavior is still worth a test (§9) — it's an existing guarantee that a future edit to `bootstrap/app.php` could silently remove.

| Method | URI | Action | Permission |
| --- | --- | --- | --- |
| GET | `/api/v1/projects` | List (same search/filter/sort/include-deleted query params as the UI) | `projects.view` |
| GET | `/api/v1/projects/{project}` | Show | `projects.view` |
| POST | `/api/v1/projects` | Create | `projects.create` |
| PUT | `/api/v1/projects/{project}` | Update details (status excluded) | `projects.edit-details` |
| PATCH | `/api/v1/projects/{project}/status` | Update status | `projects.update-status` |
| DELETE | `/api/v1/projects/{project}` | Soft delete | `projects.delete` |
| POST | `/api/v1/projects/{project}/restore` | Restore | `projects.restore` |

Phase 7 also left `install:api`'s scaffolded `GET /api/user` in place — it is not part of this table, and it is what the phase's middleware and token tests currently exercise. **Phase 8 decides its fate:** keep it (harmless, `auth:sanctum`-guarded, and the conventional way a token consumer identifies itself) or delete it, in which case the four route-level assertions in `ApiScaffoldingTest` need repointing at a `/api/v1` route.

### 5.3 API structure

- `App\Http\Controllers\Api\V1\ProjectController` (+ an invokable `ProjectStatusController` or a `status` action) — thin: authorize (middleware), validate (Form Request), delegate to `ProjectService`, return a resource.
- **Form Requests** (`IndexProjectRequest`, `StoreProjectRequest`, `UpdateProjectRequest`, `UpdateProjectStatusRequest`) hold **no rules of their own** — each `rules()` returns the matching `ProjectRules` call, as do `messages()` and `attributes()`. Same definitions as the Livewire form objects, one source of truth (§4.2).
- `IndexProjectRequest` validates the listing query string, including the `sort` whitelist and `direction` (§4.3). Invalid values are a 422, not a silently ignored parameter.
- **`ProjectResource`** (Eloquent API Resource) shapes all responses; includes client name, enum values + labels, timestamps, `deleted_at`/`deleted_by` when trashed.
- Errors: Laravel's default JSON error envelope (`401` unauthenticated, `403` forbidden, `404` model-not-found, `422` validation, `429` rate-limited with `Retry-After`) — already sanitized and consistent, and rendered as JSON rather than HTML by the existing `shouldRenderJsonWhen` config (§5.2). The only custom mapping is `StaleProjectException` → **409 Conflict** (§8.0), registered in `bootstrap/app.php`'s exception handler.

*(Note: the Livewire UI itself does not consume this API — Livewire talks to the server through its own protocol. The API exists purely for future frontends, per spec.)*

---

## 6. Validation Rules (single source of truth)

All of the below live in `App\Validation\ProjectRules` (§4.2) and are consumed unchanged by both the Livewire form objects and the API Form Requests. Nothing in this table is written twice.

### `ProjectRules::details()` — create and edit

| Field | Rules |
| --- | --- |
| `client_name` | `required`, `string`, `max:255` |
| `name` | `required`, `string`, `max:255`, `Rule::unique('projects','name')->where(client_id = resolved client)->ignore($project)` — **unique per client**, includes soft-deleted rows |
| `description` | `nullable`, `string` |
| `status` | `nullable`, `Rule::enum(ProjectStatus::class)` — create only; edit never accepts status |
| `priority` | `nullable`, `Rule::enum(ProjectPriority::class)` |
| `start_date` | `required`, `date` |
| `due_date` | `nullable`, `date`, `after_or_equal:start_date` |

### `ProjectRules::status()` — status modal / PATCH status

| Field | Rules |
| --- | --- |
| `status` | `required`, `Rule::enum(ProjectStatus::class)` |
| `changed_at` | `required`, `date`; **bounded from both directions** — see below |

The `changed_at` bound is symmetric, because the spec's invariant is `completed_at >= started_at` and either timestamp can be the one being written:

| Target status | Writes | Bound |
| --- | --- | --- |
| In Progress | `started_at` | `before_or_equal:` the stored `completed_at`, when one exists |
| Completed | `completed_at` | `after_or_equal:` the stored `started_at`, when one exists |

Only checking the Completed direction leaves the invariant reachable in two moves: `Planning → Completed` stamps `completed_at`, then `→ In Progress` stamps `started_at` at *now*, which is later — no rule fires and the row ends up with `started_at > completed_at`. Since transitions are unrestricted (§8.1.1), that path is ordinary use, not abuse. Both bounds are conditional on the other timestamp already being set, so a project that has never been completed can move to In Progress freely.

### `ProjectRules::listing()` — index query params (both surfaces)

| Param | Rules |
| --- | --- |
| `search` | `nullable`, `string`, `max:255` |
| `status` | `nullable`, `Rule::enum(ProjectStatus::class)` |
| `priority` | `nullable`, `Rule::enum(ProjectPriority::class)` |
| `sort` | `nullable`, `Rule::in(ProjectRules::SORTABLE)` — **whitelist**, see §4.3 |
| `direction` | `nullable`, `Rule::in(['asc','desc'])` |
| `with_deleted` | `nullable`, `boolean` |
| `per_page` | `nullable`, `integer`, `between:1,100` — caps the page size so a client cannot request the whole table |

### Messages

`ProjectRules::messages()` overrides the framework defaults where the stock wording leaks schema detail or reads awkwardly — e.g. the scoped-unique failure becomes "This client already has a project with that name." rather than "The name has already been taken." `ProjectRules::attributes()` maps `client_name` → "client name", `due_date` → "due date", and so on. Both surfaces get identical wording for free.

---

## 7. Why This Approach (summary)

- **Service class** — required by spec; justified because two entry points (Livewire + API) share the logic. No repositories/interfaces on top: a single Eloquent-backed service is the simplest thing that satisfies "separation of concerns."
- **Livewire form objects with validation + authorization** — required by spec; also Livewire 4's idiomatic pattern, keeps page components thin.
- **`ProjectRules` as the single rule definition** — form objects and Form Requests are bound to incompatible lifecycles (Livewire component vs HTTP container), so neither can extend the other. A stateless class both call is the only way to define each rule exactly once; without it every rule change is two edits and one of them eventually gets missed. See §4.2.
- **Form Requests for the API instead of reusing Livewire form objects** — Form Requests are the Laravel-native HTTP validation seam (automatic 422 envelope, route-model binding). They stay as thin pass-throughs to `ProjectRules`.
- **String-backed enums + `Rule::enum`** — "validate from Enum" verbatim, readable DB values, no magic integers.
- **Authorization in both layers, backend authoritative** — `@can` in Blade hides unusable controls; `Gate::authorize` in the form objects and `can:` middleware on routes are what actually refuse the write, since a Livewire action can be called without its button ever rendering. See §4.2.1.
- **Sort whitelist as a shared constant** — `orderBy()` interpolates identifiers rather than binding them, so the column must come from a fixed list. Keeping that list in `ProjectRules::SORTABLE` means the API and the UI cannot drift into allowing different columns.
- **Catch-and-refetch for client creation** — `firstOrCreate` is select-then-insert and races under concurrency; a transaction doesn't serialize it. Letting the unique index arbitrate and recovering from the violation is cheaper than locking and keeps the save successful from the user's point of view. See §4.1.
- **`can:` middleware instead of policies** — project convention (`.ai/rules/app.md`): gate abilities are the permission names; Super Admin bypass already works through `Gate::before`.
- **`is_protected` as a column, not config** — the protection rule must hold wherever a delete is attempted (UI, API, tinker-adjacent code) and survive renames of the role/account; a DB flag checked in the delete path is simpler and safer than name-matching against config.
- **Rate limiting keyed on the user, not the IP** — staff behind one office NAT would otherwise share a bucket and throttle each other; the IP fallback still covers unauthenticated probing. See §5.2.
- **No middleware for JSON error rendering** — `bootstrap/app.php` already handles it via `shouldRenderJsonWhen(… is('api/*') …)`. Checking what the app and framework already do before adding a layer is the point; see §5.2, where the same check also determined that `'api'` must not be listed on the route group and that `throttleApi()` must be enabled explicitly.
- **Query-builder updates must `refresh()`** — the guarded `update()` bypasses model events and leaves the in-memory model holding a stale `version`, which would fail the caller's *next* save. See §8.0.
- **Sanctum** — see §5.1, including the deferred-token-abilities trade-off.
- **Clients created inline, no client CRUD** — spec says new clients are added on project creation; a separate client management module is out of scope and would be over-engineering.

## 8. Remaining Assumptions & Decisions (flagged per spec)

### 8.0 Optimistic locking — **decided: in scope**

Two project managers opening the same project means the second save silently overwrites the first, and neither user is told. Silent data loss is the worst class of bug to defer, because nothing surfaces it — no error, no log, no complaint until someone notices their edit vanished.

The fix is small enough that deferring it is not the cheaper option:

- `projects.version` — unsigned integer, default 1.
- Edit and status forms carry the loaded `version` as a hidden field.
- `ProjectService::updateDetails()` / `updateStatus()` update `where('id', …)->where('version', $expected)` and increment `version` in the same statement. **Zero affected rows means someone else saved first** → throw `StaleProjectException`.
- **The guarded update must `refresh()` before returning.** A query-builder `update()` writes straight to the database: it fires no model events and does **not** touch the in-memory `$project`, so the object still holds the old attributes *and* the old `version`. Both methods return `Project`, so returning the unrefreshed instance hands the caller stale data — and, worse, a stale `version` that would fail the *next* save. The affected-rows check is the easy half of this pattern to get right; forgetting the refresh is the half that bites:

```php
$affected = Project::whereKey($project)
    ->where('version', $expectedVersion)
    ->update([...$data, 'version' => DB::raw('version + 1')]);

if ($affected === 0) {
    throw new StaleProjectException($project->fresh());
}

return $project->refresh();
```

  The same applies to anything relying on model events — observers, `saving`/`saved` hooks, and touched parent relations do **not** run. `updated_at` *is* maintained, since `Project::whereKey(…)` returns an Eloquent builder and `Builder::update()` passes values through `addUpdatedAtColumn()`; only the raw query builder (`DB::table('projects')`) would skip it, and this isn't that.
- **UI:** caught in the Livewire action → toast "This project was updated by someone else. Refresh to see the latest version." with the form left intact so nothing typed is lost.
- **API:** rendered as **409 Conflict** with the current `version`, so a future frontend can resolve it however it likes.

Total cost: one column, one `where` clause, one catch block per surface. Retrofitting it later costs a migration plus an audit of every write path — strictly more.

*Why `version` and not `updated_at`:* `updated_at` has second-level precision on MySQL, so two saves inside the same second would both pass the check and the guarantee would quietly not hold. An integer that only ever increments has no such window.

**`delete()` and `restore()` deliberately take no `$expectedVersion`.** They are guarded by a confirmation modal showing the project, and they don't *overwrite* field values — the concurrent outcomes are "deleted twice" (the second is a no-op on an already-trashed row) and "deleted while someone was editing." Neither loses data the way a blind field overwrite does: the edit either landed before the delete and is preserved in the trashed row, or it hits a trashed row and fails on its own version check. Version-guarding the delete would mean a PM whose list is a minute stale gets a conflict error instead of the deletion they asked for — friction with nothing recovered. If it later turns out that deleting a project someone else just edited needs to be caught, the column is already there and it's a one-line addition to those methods.

*If you'd rather cut the whole thing:* drop the column, the hidden field, and the two catch blocks — the rest of the plan is unaffected, and the behavior falls back to last-write-wins. Say the word and it comes out; it is written up as included because the cost/benefit favors keeping it.

### 8.1 Other assumptions

1. **Status transitions are unrestricted** (any status → any status). Spec defines timestamp side-effects, not a workflow; a state machine would be over-engineering. Moving *away* from Completed/In Progress keeps the previously stored timestamps — which is exactly why the `changed_at` bound in §6 has to be symmetric: with free transitions, `started_at` can be written *after* a `completed_at` already exists.
2. **`start_date` default is applied in the form** (UI prefill), not as a DB default — keeps the column honest and the behavior visible.
3. **Client deletion is out of scope**; `client_id` uses `restrictOnDelete` as a safety net.
4. **"Display active projects only"** is interpreted as *non-deleted* (soft deletes' default behavior), not a status filter — because the same bullet's checkbox toggles *deleted* projects.
5. **New base folders `app/Enums/`, `app/Services/`, `app/Validation/`, `app/Exceptions/`** will be created. `ProjectRules` lives in `app/Validation/` rather than `app/Rules/` because `app/Rules/` is where `php artisan make:rule` puts `ValidationRule` *objects*, and `ProjectRules` is a rule **definition** class, not a rule object — keeping them apart avoids implying an interface it doesn't implement. If custom `ValidationRule` objects are added later, `app/Rules/` stays free for them.
6. **API list endpoint mirrors UI query params** (`search`, `status`, `priority`, `sort`, `direction`, `with_deleted`, `per_page`) — spec says "endpoints of the features," so feature parity is assumed. Both are validated by `ProjectRules::listing()`, so the whitelist can't apply to only one of them.
7. **Two roles are seeded: Super Admin and Project Manager.** Super Admin holds no permission rows and passes every gate via `Gate::before`; Project Manager holds the `projects.*` set. "Staff/Admin" from the goal statement is *not* seeded as a role — those users are expected to get `projects.*` through roles created in the (future) role management UI.

## 9. Implementation Phases

Phase 0 is **done** (this commit). Each later step ends green on `vendor/bin/pint --dirty --format agent` + the listed test filter.

```
0. ✅ Seeder + permissions groundwork: projects.update-status rename, projects.restore,
   Project Manager role seeded with projects.*, is_protected on users/roles,
   Super Admin role + account protected
   → verified: php artisan test --compact tests/Feature/RolePermissionTest.php (12 passed)
1. ✅ Migrations, enums, models, factories, seeders (clients w/ name_key, projects w/ version
   + composite unique on client_id+name)
   → verified: php artisan test --compact --filter=ProjectModel (11 passed — casts, soft
     delete, relationships)
2. ✅ ProjectRules (details/status/listing/messages/attributes + SORTABLE)
   → verified: php artisan test --compact --filter=ProjectRules (32 passed)
     (scoped-unique passes for a same-named project under a different client; sort whitelist
      rejects an unlisted column; per_page cap; message wording)
3. ✅ ProjectService (race-safe client resolution, updateDetails, updateStatus, delete, restore,
   StaleProjectException)
   → verified: php artisan test --compact --filter=ProjectService (25 passed)
4. ✅ Livewire form objects (delegating to ProjectRules + Gate::authorize)
   → verified: php artisan test --compact --filter=ProjectForm (21 passed)
5. ✅ Livewire pages: index (search/filter/sort/include-deleted/restore), create, edit
   → verified: php artisan test --compact --filter=ProjectIndex|ProjectCreate|ProjectEdit
     (58 passed — assertSee, permission denials, filter results, query-count/N+1, sort tampering)
6. ✅ Status modal + delete modal (confirmations, timestamp defaults, cross-field date rule)
   → verified: php artisan test --compact --filter=ProjectStatus|ProjectDelete (23 passed)
7. ✅ php artisan install:api (approved) — Sanctum, routes/api.php, the api: entry in
   withRouting, personal_access_tokens migration; HasApiTokens on User; throttleApi()
   in bootstrap/app.php AND RateLimiter::for('api') in AppServiceProvider, which moved
   here from phase 8 because throttle:api without a defined limiter is a 500 on every
   authenticated request, not merely an unthrottled one (§5.2)
   → verified: php artisan test --compact tests/Feature/ApiScaffoldingTest.php (9 passed)
     route:list CANNOT verify the middleware stack — it prints the unexpanded group name
     ('api'), never its members, so the once-and-only-once check is a test (§9).
8. ✅ API v1: Form Requests (thin ProjectRules wrappers), ProjectResource, controllers,
   can: middleware, StaleProjectException → 409. Three things the plan did not call for but
   the build needed: ProjectRules::version(), because only the API validates the locking
   counter (a form object holds it as a property); a ProjectRequest base class, so the four
   requests differ only in rules(); and Project::listing(), a scope holding the search,
   filters, sort and eager load that the index page previously kept inline — §4.3 called for
   a shared query builder, and the API list endpoint is the second caller that made it one.
   → verified: php artisan test --compact --filter=Api (68 passed)
     (auth 401, permission 403, validation 422, conflict 409, throttled 429,
      JSON error body without an Accept header, happy paths per endpoint)
9. ✅ Full suite: composer test (pint + phpstan + pest)
   → verified: composer test — pint passed, phpstan 0 errors, 284 tests / 797 assertions passed
```

### Test coverage highlights

**Tests assert behavior, not permission data.** Which permissions a role holds is administrator-owned data that legitimately changes over time (§2.14). A test pinning a literal list of grants would have to be edited on every valid change, so it would never catch anything — the response to the failure is always "update the test." Role/permission tests therefore reference `RolePermissionSeeder::PROJECT_MANAGER_PERMISSIONS` rather than restating it, and assert the behavior that does hold forever: the role is created with its declared defaults, declared slugs are real permissions, the permission cache resolves, and **re-seeding never revokes a runtime change**. The security boundary is covered where it actually lives — the `Gate::authorize` / `can:` tests below.

**Authorization**
- Guests get 401 (API) / redirected (web); authenticated users without the permission get 403 on **every** write action — asserted per action, invoking the Livewire method directly rather than clicking a button, to prove the server-side gate is what refuses it (§4.2.1).
- Buttons for actions the user cannot perform are not rendered.

**Validation**
- `due_date < start_date` rejected; invalid enum values rejected.
- **Both timestamp directions:** completing with a `changed_at` earlier than `started_at` is rejected, **and** the reverse — a project taken `Planning → Completed → In Progress` cannot stamp a `started_at` later than its stored `completed_at`. The second case is the one a one-directional rule misses (§6).
- **Rule parity is asserted on a value that includes the scope:** the `unique` rule uses the scalar `->where('client_id', …)` form, so comparing the two surfaces' rules catches a divergence in the client scoping. With a closure the comparison would either always fail or pass vacuously (§4.2) — the test asserts the rendered rule string contains `client_id` so a regression to the closure form fails loudly rather than going green.
- **Per-client name uniqueness:** two clients may each own a project called "Website Redesign"; a second "Website Redesign" under the *same* client is rejected; a name held by a *soft-deleted* project of that client is still rejected.
- **Sort whitelist:** `sort=name` works; `sort=password` and `sort=name); drop table projects;--` are a 422 on the API and fall back to the default sort in Livewire without throwing. Same for `direction`.
- **Tampered listing state:** mounting the index component with an invalid `status`, `priority`, `per_page` or `with_deleted` in the query string resets that property to its default and still renders a list — asserted per property, since these are `#[Url]`-bound and reachable without touching a `flux:select`.
- `per_page` above the cap is rejected on the API and clamped in the component.
- Rule parity: a test asserts `ProjectForm::rules()` and `StoreProjectRequest::rules()` produce the same array, so the two surfaces cannot drift.

**Behavior**
- Creating a project with an unknown client name creates the client; a known name reuses it; `"  ACME   corp "` resolves to the existing "Acme Corp" rather than creating a second client. `Client::keyFor()` is unit-tested directly, since both the service and the validation layer depend on it agreeing with itself (§4.1).
- The client-creation race is covered by inserting a conflicting client between resolve and save (a `saving` hook in the test) and asserting the project still saves against the winning client — no exception reaches the user.
- Status → In Progress sets `started_at`; → Completed sets `completed_at`; supplied datetimes are stored.
- Delete records `deleted_by` and soft-deletes; restore clears `deleted_by`; default list excludes trashed; toggle includes them.
- **Stale write:** saving with an out-of-date `version` leaves the row unchanged and surfaces a toast (UI) / 409 (API); the winning edit survives intact.
- **Post-update freshness:** the `Project` returned by `updateDetails()` carries the new attributes *and* the incremented `version` — asserted by saving twice in a row through the returned instance, which fails if the object was not refreshed.
- Protected role/user deletion is refused for every caller, including Super Admin (when role/user delete features are built).

**API transport**
- A request with **no `Accept` header** to a nonexistent project returns a JSON 404, not an HTML page — pinning the existing `shouldRenderJsonWhen` guarantee so a later edit to `bootstrap/app.php` can't silently drop it.
- The middleware stack on every API route is what's intended — `throttle:api` exactly **once** and `SubstituteBindings` exactly once (§5.2). Asserted by walking each `api/*` route through `Router::gatherRouteMiddleware()`, which resolves the group into its members. `route:list --path=api -v` is not the check: it prints the group *name* and nothing inside it, so two `throttle` entries and zero would look identical there. The test iterates whatever routes the file holds, so it keeps covering the v1 endpoints added in phase 8.
- A named `api` limiter is defined at all — a one-line guard against the §5.2 failure, since every other API test would pass with `throttleApi()` removed but only this one fails if the limiter is.
- Exceeding the rate limit returns 429 with a `Retry-After` header, and two different users do not share a bucket (the NAT case) — asserted through real requests.
- The guest bucket keys on the IP — asserted by **invoking the limiter closure directly**, not through a route. It cannot be observed through the middleware: `Authenticate` sorts ahead of `ThrottleRequests` in the framework's priority list, so an unauthenticated request is refused with a 401 before the limiter increments anything (§5.2).

**Performance**
- Index query count is constant regardless of row count (eager-loaded `client`), asserted with `DB::listen` at 1 vs 20 projects.
