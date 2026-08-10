# Project Tracker

Project management for staff and project managers — create projects against a client, track status
and priority, and soft-delete or restore them. Built with Laravel 13, Livewire 4 and Flux UI, with
role-based permissions throughout and a JSON API for future frontends.

## Requirements

Install these first. `composer setup` uses them; it does not install them.

| | Version | Check with |
| --- | --- | --- |
| PHP | **8.5 or newer** | `php -v` |
| Composer | 2.x | `composer --version` |
| Node.js | 20.19+ or 22.12+ (Vite 8) | `node -v` |

SQLite is the default database and needs no server — the setup command creates the file.

**PHP 8.5 is a hard requirement**, declared as `"php": "^8.5"` in `composer.json`. On anything older,
`composer install` refuses to run rather than installing a broken tree:

```
laravel/livewire-starter-kit dev-main requires php (^8.5)
```

[Laravel Herd](https://herd.laravel.com) is the quickest way to get it on macOS or Windows — it
bundles PHP, so installing Herd covers the first row of the table.

## Setup

```bash
git clone git@github.com:lyndonjohn/project-tracker.git
cd project-tracker
composer setup
```

`composer setup` runs these steps, in order:

1. `composer install` — downloads this project's PHP packages into `vendor/`
2. copies `.env.example` to `.env`, unless you already have one
3. creates the empty database file at `database/database.sqlite`
4. `php artisan key:generate` — writes `APP_KEY` into `.env`
5. `php artisan migrate` — creates the tables
6. `php artisan db:seed` — roles, permissions, the Super Admin account, and sample projects
7. `npm install` — downloads this project's Node packages into `node_modules/`
8. `npm run build` — compiles the CSS and JavaScript

Then serve it:

```bash
composer dev
```

The app is then at **http://localhost:8000**, with the Vite dev server, a queue listener and a log
tail running alongside it. If you use [Laravel Herd](https://herd.laravel.com), the site is already
served at `http://project-tracker.test` and you only need `npm run dev` for asset hot-reloading.

## Logging in

The seeder creates a Super Admin account from the `SUPER_ADMIN_*` values in `.env`:

| Email | Password |
| --- | --- |
| `superadmin@example.com` | `password` |

**These are local development defaults.** Set real values through the environment anywhere this is
deployed — the seeder creates the account only when both variables are present, so leaving them
unset in production is safe.

Registering a new account through the UI gets you a user with **no role**, which by design cannot
reach the project pages. Grant that user a role, or use the Super Admin account above.

## What's in it

**Projects** (`/projects`) — a paginated list with search across project name, client name and
description; filters for status, priority and a due-date range; sortable columns; and a toggle for
including deleted projects.

- **Create / edit** — the client is a free-text autocomplete: type an existing client to reuse it, or
  a new name to create it on save. Project names are unique *per client*, so two clients can each
  have a "Website Redesign".
- **Status** — changed through a confirmation modal that stamps `started_at` when a project moves to
  In Progress and `completed_at` when it moves to Completed, defaulting to now but editable.
- **Delete / restore** — soft deletes, recording who deleted the project; deleted rows can be
  restored from the list.
- **Concurrent edits** — projects carry a version counter. If someone else saves while you have the
  form open, your save is refused with a message rather than silently overwriting their work.

**Roles and permissions** — six project permissions (`projects.view`, `create`, `update-status`,
`edit-details`, `delete`, `restore`) are assigned to roles, never directly to users. A **Project
Manager** role is seeded holding all six. The **Super Admin** role holds no permission rows and
passes every check through a `Gate::before` bypass. Both the Super Admin role and account are
flagged protected and can never be deleted, by anyone.

**API** — the same features over JSON at `/api/v1`, for a future frontend. See [API](#api) below.

## API

A versioned JSON API covering everything the UI does. The Livewire pages do not consume it — they
talk to the server over Livewire's own protocol — so the two surfaces share the service and
validation layers rather than one calling the other.

### Authenticating

Requests carry a Sanctum bearer token. There is no token endpoint; issue one for a user from the
console:

```bash
php artisan tinker --execute 'echo App\Models\User::where("email", "superadmin@example.com")->sole()->createToken("my-integration")->plainTextToken;'
```

```bash
curl -H "Authorization: Bearer <token>" -H "Accept: application/json" https://project-tracker.test/api/v1/projects
```

A token acts as its owner and carries **all** of that user's permissions — abilities are not scoped
yet. Treat one as equivalent to the owner's password, and issue a separate token per integration so
any one of them can be revoked on its own.

Rate limit: **60 requests per minute**, counted per user (falling back to the IP for unauthenticated
requests), so staff behind one office NAT do not share a bucket. Exceeding it returns `429` with a
`Retry-After` header.

### Endpoints

Every route requires authentication plus the listed permission.

| Method | URI | Purpose | Permission |
| --- | --- | --- | --- |
| GET | `/api/v1/projects` | List, with the UI's search, filters and sorting | `projects.view` |
| GET | `/api/v1/projects/{project}` | Read one | `projects.view` |
| POST | `/api/v1/projects` | Create, creating the client if it is new | `projects.create` |
| PUT | `/api/v1/projects/{project}` | Update details (not status) | `projects.edit-details` |
| PATCH | `/api/v1/projects/{project}/status` | Change status, stamping its timestamp | `projects.update-status` |
| DELETE | `/api/v1/projects/{project}` | Soft delete | `projects.delete` |
| POST | `/api/v1/projects/{project}/restore` | Restore a deleted project | `projects.restore` |

### Listing

`GET /api/v1/projects` accepts the same parameters the index page uses, so a client can reproduce
any list a user can see:

| Parameter | Accepts |
| --- | --- |
| `search` | Matches project name, description, or client name |
| `status` | `planning`, `in_progress`, `on_hold`, `completed` |
| `priority` | `low`, `medium`, `high` |
| `due_from`, `due_to` | Dates; applied as a range, and only when both are given |
| `sort` | `name`, `client_name`, `due_date`, `status`, `priority`, `created_at` |
| `direction` | `asc`, `desc` (default `desc`) |
| `with_deleted` | Boolean; deleted projects are excluded unless this is set |
| `per_page` | 1–100 (default 25) |

Anything outside those values is a `422` rather than a silently ignored parameter — the sort
whitelist in particular is what keeps user input out of `orderBy()`.

```bash
curl -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  'https://project-tracker.test/api/v1/projects?search=acme&status=in_progress&sort=due_date&direction=asc&per_page=25'
```

```json
{
  "data": [
    {
      "id": 7,
      "name": "Blair Campos",
      "description": "Quos autem quibusdam",
      "client": { "id": 9, "name": "Berk Dunlap" },
      "status": { "value": "planning", "label": "Planning" },
      "priority": { "value": "low", "label": "Low" },
      "start_date": "2026-08-10",
      "due_date": "2026-08-10",
      "started_at": null,
      "completed_at": null,
      "version": 1,
      "created_at": "2026-08-10T08:41:21+00:00",
      "updated_at": "2026-08-10T08:41:21+00:00"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 5, "per_page": 1, "total": 5 }
}
```

`status` and `priority` are given as both value and label, so a client can display one and filter on
the other without keeping its own copy of the wording. Both keys are **omitted entirely** when the
project has no status or priority set. `deleted_at` and `deleted_by` appear only on deleted
projects.

### Creating and updating

```bash
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"client_name":"Initech","name":"Intranet","priority":"high","start_date":"2026-09-01","due_date":"2026-12-01"}' \
  https://project-tracker.test/api/v1/projects
```

`client_name` is free text: an existing client is matched case- and whitespace-insensitively, and an
unknown name creates one. Project names are unique **per client**, so two clients may each have a
"Website Redesign" — including against a *deleted* project, which still holds its name so that
restoring it cannot produce a duplicate.

Status is not accepted by `PUT`; it has its own endpoint because it also stamps a timestamp and
carries its own permission:

```bash
curl -X PATCH -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"status":"in_progress","changed_at":"2026-09-02 09:30:00","version":1}' \
  https://project-tracker.test/api/v1/projects/8/status
```

Moving to In Progress stamps `started_at`, and to Completed stamps `completed_at`, using
`changed_at` (any datetime, not just now). The bound is symmetric: you cannot complete a project
before it started, nor start one after it was completed.

### Concurrent writes

Every project carries a `version`. `PUT` and `PATCH` require the version you last read, and a write
against a stale one changes nothing and returns **409**:

```json
{
  "message": "This project was updated by someone else.",
  "version": 2
}
```

The current version comes back so a client can re-read, merge and retry. `DELETE` and restore take
no version — they do not overwrite field values, so a stale caller deleting a project still deletes
the project they meant to.

### Responses

| Status | When |
| --- | --- |
| `200` / `201` / `204` | Read or write succeeded; `201` on create, `204` on delete |
| `401` | Missing or invalid token |
| `403` | Authenticated, but the user's roles do not grant the permission |
| `404` | No such project, or it is deleted and the route does not include deleted ones |
| `409` | Version conflict — someone else saved first |
| `422` | Validation failed; `errors` is keyed by field |
| `429` | Rate limited; retry after the `Retry-After` header |

Errors are JSON regardless of the `Accept` header, since every route here is under `api/*`:

```json
{
  "message": "The selected sort is invalid.",
  "errors": { "sort": ["The selected sort is invalid."] }
}
```

## Tests and checks

```bash
composer test
```

Runs Pint (formatting), Larastan level 7 (static analysis) and the Pest suite together — the same
gate used before every commit. Individually:

```bash
php artisan test --compact
vendor/bin/pint
composer types:check
```

## Layout

| Path | What lives there |
| --- | --- |
| `app/Models` | `Project`, `Client`, `User` |
| `app/Enums` | `ProjectStatus`, `ProjectPriority` |
| `app/Validation` | `ProjectRules` — every validation rule, shared by Livewire and the API |
| `app/Services` | `ProjectService` — the business logic both surfaces call |
| `app/Livewire/Forms` | Livewire form objects, which authorize and validate |
| `app/Http/Controllers/Api/V1` | API controllers — thin, delegating to the service |
| `app/Http/Requests/Api/V1` | Form Requests, each a pass-through to `ProjectRules` |
| `app/Http/Resources` | `ProjectResource`, the shape of every API response |
| `resources/views/pages` | Livewire single-file page components (`⚡name.blade.php`) |
| `.ai/rules` | Project conventions, scoped to the paths they govern |

## Documentation

[`docs/implementation-plan.md`](docs/implementation-plan.md) is the design record: the data model,
the architecture and the reasoning behind each decision — why validation lives in one shared class,
why client creation catches a unique-constraint violation instead of locking, why optimistic locking
is in scope, and what was deliberately left out.
