# Project Tracker

Project management for staff and project managers — create projects against a client, track status
and priority, and soft-delete or restore them. Built with Laravel 13, Livewire 4 and Flux UI, with
role-based permissions throughout and a JSON API for future frontends.

## Prerequisites

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

## Running the application

```bash
composer dev
```

The app is then at **http://localhost:8000**, with the Vite dev server, a queue listener and a log
tail running alongside it. Stop it with `Ctrl-C`.

If you use [Laravel Herd](https://herd.laravel.com), the site is already served at
`http://project-tracker.test` and you only need `npm run dev` for asset hot-reloading. Run
`herd secure` if you want it over HTTPS.

Log in with one of the accounts below, then go to **Projects** in the sidebar. The API is at
`/api/v1` — see [API](#api).

## Logging in

Seeding creates two accounts, both with the password `password`:

| Email | Role | Use it to |
| --- | --- | --- |
| `superadmin@example.com` | Super Admin | See everything — this account passes **every** permission check through a `Gate::before` bypass |
| `pm@example.com` | Project Manager | Exercise the app as a normally-permissioned user, where permissions actually decide things |

Prefer `pm@example.com` when you want to see the authorization behave. Because the Super Admin
bypasses every gate, nothing it does can ever produce a 403, which makes the permission checks look
like they do nothing.

**Both are local development defaults.** The Super Admin comes from the `SUPER_ADMIN_*` values in
`.env`, so set real ones through the environment anywhere this is deployed — the seeder creates that
account only when both variables are present. The Project Manager is seeded **only outside
production**, alongside the sample projects.

Registering a new account through the UI gets you a user with **no role**, which by design cannot
reach the project pages — useful for seeing a refusal firsthand.

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

## Technology choices

| Choice | Why |
| --- | --- |
| **Laravel 13** (PHP 8.5) | One of the permitted backend options, and the one whose batteries — Eloquent, validation, policies/gates, queues, first-party auth — cover most of this brief without third-party glue. |
| **Livewire 4 + Flux UI 2** | The UI is server-rendered from the same PHP that owns the rules, so validation and authorization exist once instead of being restated in a client. See the note below on the frontend options. |
| **SQLite** | Permitted, and the only option that needs no server: `composer setup` creates a file and the app runs. The code is driver-agnostic — see the due-date note in `Project::listing()` for a place where that was deliberate. |
| **Fortify** | First-party authentication (login, registration, password reset, 2FA, passkeys) without hand-rolling it, and headless, so it does not dictate the UI. |
| **spatie/laravel-permission** | Roles and permissions are the standard solution here; writing a fifth-rate version of it would not have been a better use of the time. |
| **Sanctum** | Token auth for the API. First-party, and it covers both futures — bearer tokens for a separate frontend, cookie auth for a same-domain SPA — without OAuth2's machinery, which nothing here needs. |
| **Pest 5, Pint, Larastan (level 7)** | Tests, formatting and static analysis, all runnable as one `composer test`. |

**On the frontend options.** The brief lists React, Vue, Angular and Next.js. This uses Livewire,
which is Laravel's own server-rendered component layer, because splitting a CRUD app across two
codebases would have meant maintaining the validation and permission rules twice — the single
biggest source of drift in an app this shape. The JSON API is built and documented precisely so a
React or Vue frontend can be added later without touching the domain layer: it already covers every
feature the UI has.

## API

A versioned JSON API covering everything the UI does. The Livewire pages do not consume it — they
talk to the server over Livewire's own protocol — so the two surfaces share the service and
validation layers rather than one calling the other.

**[`docs/api.md`](docs/api.md) is the full reference** — every endpoint, every parameter, every
request variant and the exact response it returns, captured from a running instance. What follows
here is the short version: how to get a token and make your first call.

### Authenticating

Requests carry a Sanctum bearer token. There is no token endpoint — tokens are issued from the
console, for any user:

```bash
php artisan api:token pm@example.com
```

It prints the user, the roles the token will therefore carry, and the token itself. Sanctum stores
only a hash, so that plain text is shown once and cannot be recovered afterwards — copy it when it
appears. Pass `--name` to label one (`php artisan api:token pm@example.com --name=postman`) so it
can be revoked without affecting the others.

### Trying it in Postman

1. Run `php artisan api:token pm@example.com` and copy the token it prints.
2. In Postman, set the request's **Authorization** type to **Bearer Token** and paste it — or add the
   header by hand as `Authorization: Bearer <token>`.
3. Add an `Accept: application/json` header.
4. `GET http://project-tracker.test/api/v1/projects`

Issue a token for `superadmin@example.com` to watch every endpoint succeed, and one for an account
with fewer permissions to watch the same request come back `403`.

```bash
curl -H "Authorization: Bearer <token>" -H "Accept: application/json" http://project-tracker.test/api/v1/projects
```

The examples below use `http://project-tracker.test`, which is what Herd serves until you run
`herd secure`. Swap in whatever host and scheme you serve the app on — `http://localhost:8000` under
`composer dev`, or `https://` once the site is secured. Nothing in the API depends on which.

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
  'http://project-tracker.test/api/v1/projects?search=acme&status=in_progress&sort=due_date&direction=asc&per_page=25'
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
  http://project-tracker.test/api/v1/projects
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
  http://project-tracker.test/api/v1/projects/8/status
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

## Assumptions

Decisions the [brief](https://github.com/koda-devteam-ops/fullstack-developer-assessment/blob/main/REQUIREMENTS.md)
did not specify. Two of them change how a listed endpoint behaves, so they lead.

### Two deviations worth knowing about first

**`DELETE /projects/:id` soft-deletes.** The brief says "Delete project". The row is marked deleted,
records who deleted it, and can be restored; it is gone from every default list and the API returns
`404` for it afterwards, so a consumer sees a delete. It is still in the table. Losing a client's
project history to one mis-click seemed the worse failure, and restoring costs two columns and a
route.

**`PUT /projects/:id` does not change status.** The brief says "Update existing project". Status
moves through `PATCH /projects/:id/status` instead, because changing it also stamps `started_at` or
`completed_at` and is a separately granted permission. A `status` sent to `PUT` is ignored rather
than rejected — everything else in the body still applies.

### Data model

- **Clients are their own table, not a string on the project.** The brief lists Client Name as a
  project field. Typing it per project means "Acme Corp" and "acme corp" become two clients and no
  one can list a client's work. The form takes free text, matches an existing client
  case- and whitespace-insensitively, and creates one only when the name is new.
- **No client management screen.** Clients exist only as a by-product of creating a project — a CRUD
  module for them was not asked for.
- **Project names are unique per client, not globally.** Two projects called "Website Redesign" under
  one client is a data-entry mistake; one each for two clients is normal. A name held by a *deleted*
  project still counts, so restoring it can never produce a duplicate.
- **Status and Priority are optional; Start Date is required, Due Date is not.** The brief requires
  Client Name and Project Name, and says Status and Priority must be *valid* — which is a rule about
  the value, not about it being present. A project being logged before anyone has decided its
  priority is ordinary.
- **`started_at` and `completed_at` are stored** when a project moves to In Progress or Completed,
  with an editable timestamp. Not in the brief's model, but "monitor progress" is its stated purpose
  and neither the status alone nor `updated_at` answers when work actually began or finished.
- **Projects carry a `version` counter.** Two managers editing the same project would otherwise mean
  the second save silently overwrites the first with nobody told. The second save is refused instead.

### API

- **Endpoints are under `/api/v1`,** not at the root. Versioning now costs a path segment; adding it
  after a consumer exists costs a migration for them.
- **`GET /projects` is paginated** — 25 per page, `per_page` capped at 100. The brief says "Retrieve
  all projects"; an unbounded list is fine at nine rows and a self-inflicted outage at fifty
  thousand. `meta` and `links` make the whole set reachable.
- **Every endpoint requires authentication and a specific permission.** Authentication is a bonus
  item in the brief; a single "is logged in" check was extended to six permissions
  (`projects.view`, `create`, `update-status`, `edit-details`, `delete`, `restore`) because "project
  managers" and "an agency" imply people with different reach over the same data.
- **60 requests per minute per user.** Not requested. Token auth means a leaked token replays as fast
  as the network allows, and every other input in the app is already bounded.
- **Sort columns come from a whitelist.** `orderBy()` interpolates identifiers rather than binding
  them, so an unlisted column is a `422` on the API and falls back to the default in the UI.
- **Token abilities are not scoped.** A token carries all of its owner's permissions. Scoping them
  without a real integration to model would have been guesswork — stated here so it is a known
  trade-off rather than a surprise.

### Behaviour

- **Any status may follow any other.** The brief defines four status values, not a workflow, so no
  transition is blocked. Because of that, the timestamp rule is symmetric: a project cannot be
  completed before it started, *nor* started after it was completed.
- **"All projects" means all live projects.** Deleted ones appear only behind the "include deleted"
  toggle, or `?with_deleted=1`.
- **Seeded data.** Three clients with nine projects, and the two accounts above, so the app is not
  an empty shell on first run. Seeded outside production only.

### Scope

Bonus items **included**: search, filtering by status and priority, sorting, authentication, and
tests (covering the domain, both surfaces, and the authorization boundary — run `composer test`).

Bonus items **not included**: Docker and deployment. The brief calls for quality over quantity, and
a Docker path that had not been run end-to-end would have been a liability rather than a feature —
`composer setup` is the tested route in.

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
| `app/Console/Commands` | `api:token`, which issues an API token for a user |
| `app/Http/Controllers/Api/V1` | API controllers — thin, delegating to the service |
| `app/Http/Requests/Api/V1` | Form Requests, each a pass-through to `ProjectRules` |
| `app/Http/Resources` | `ProjectResource`, the shape of every API response |
| `resources/views/pages` | Livewire single-file page components (`⚡name.blade.php`) |
| `.ai/rules` | Project conventions, scoped to the paths they govern |

## Documentation

[`docs/api.md`](docs/api.md) is the API reference — every endpoint with every request variant and
the response it returns.

[`docs/implementation-plan.md`](docs/implementation-plan.md) is the design record: the data model,
the architecture and the reasoning behind each decision — why validation lives in one shared class,
why client creation catches a unique-constraint violation instead of locking, why optimistic locking
is in scope, and what was deliberately left out.
