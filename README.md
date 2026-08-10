# Project Tracker

Project management for staff and project managers — create projects against a client, track status
and priority, and soft-delete or restore them. Built with Laravel 13, Livewire 4 and Flux UI, with
role-based permissions throughout and a JSON API for future frontends.

## Requirements

- PHP 8.5
- Composer
- Node.js 20.19+ or 22.12+ (Vite 8)
- SQLite (the default; no server to configure)

## Setup

```bash
git clone git@github.com:lyndonjohn/project-tracker.git
cd project-tracker
composer setup
```

`composer setup` installs the PHP and Node dependencies, writes `.env`, generates the app key,
creates the SQLite database, runs the migrations, seeds roles, permissions and sample projects,
and builds the frontend assets.

Then serve it:

```bash
composer dev
```

The app is then at **http://localhost:8000**, with the Vite dev server, a queue listener and a log
tail running alongside it. If you use [Laravel Herd](https://herd.laravel.com), the site is already
served at `https://project-tracker.test` and you only need `npm run dev` for asset hot-reloading.

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

**API** — Sanctum is installed and `/api/*` routes are rate limited to 60 requests/minute per user.
The versioned `/api/v1` project endpoints are the current work in progress; this section will be
expanded when they land.

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
| `resources/views/pages` | Livewire single-file page components (`⚡name.blade.php`) |
| `.ai/rules` | Project conventions, scoped to the paths they govern |

## Documentation

[`docs/implementation-plan.md`](docs/implementation-plan.md) is the design record: the data model,
the architecture and the reasoning behind each decision — why validation lives in one shared class,
why client creation catches a unique-constraint violation instead of locking, why optimistic locking
is in scope, and what was deliberately left out.
