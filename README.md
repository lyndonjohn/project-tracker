# Project Tracker

A tool for a digital agency to track client projects. You can create projects for a client, set
their status and priority, search and filter the list, and delete or restore them.

Built with Laravel 13, Livewire 4 and Flux UI. Access is controlled by roles and permissions, and
there is a JSON API for a future frontend.

## Prerequisites

Install these first. `composer setup` uses them, it does not install them.

| | Version | Check with |
| --- | --- | --- |
| PHP | **8.5 or newer** | `php -v` |
| Composer | 2.x | `composer --version` |
| Node.js | 20.19+ or 22.12+ (needed by Vite 8) | `node -v` |

SQLite is the default database. It needs no server, and the setup command creates the file for you.

**PHP 8.5 is required.** It is set as `"php": "^8.5"` in `composer.json`, so on an older version
`composer install` stops instead of installing a broken set of packages:

```
laravel/livewire-starter-kit dev-main requires php (^8.5)
```

If you do not have PHP 8.5, [Laravel Herd](https://herd.laravel.com) is the easiest way to get it on
macOS or Windows. It includes PHP, so installing Herd covers the first row of the table.

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
6. `php artisan db:seed` — roles, permissions, the demo accounts, and sample projects
7. `npm install` — downloads this project's Node packages into `node_modules/`
8. `npm run build` — compiles the CSS and JavaScript

## Running the application

```bash
composer dev
```

The app is then at **http://localhost:8000**. This also starts the Vite dev server, a queue listener
and a log tail. Press `Ctrl-C` to stop them all.

If you use [Laravel Herd](https://herd.laravel.com), the site is already served at
`http://project-tracker.test` and you only need `npm run dev` for asset reloading. Run `herd secure`
if you want it over HTTPS.

Log in with one of the accounts below, then open **Projects** in the sidebar. The API lives at
`/api/v1`, described under [API](#api).

## Logging in

Seeding creates two accounts. Both use the password `password`.

| Email | Role | What it is for |
| --- | --- | --- |
| `superadmin@example.com` | Super Admin | Seeing everything. This account passes every permission check automatically. |
| `pm@example.com` | Project Manager | Using the app as a normal user, where permissions actually decide what you can do. |

Use `pm@example.com` if you want to see the permission checks working. The Super Admin skips all of
them, so it can never be refused, which makes the checks look like they do nothing.

**Both are local development defaults.** The Super Admin details come from the `SUPER_ADMIN_*` values
in `.env`, so set real ones through the environment if you deploy this. That account is only created
when both values are set. The Project Manager account is only seeded outside production, along with
the sample projects.

If you register a new account through the UI, it has no role and cannot open the project pages. That
is on purpose, and it is a quick way to see a refusal for yourself.

## What's in it

**Projects** (`/projects`) is a paginated list. You can search by project name, client name or
description, filter by status, priority and a due-date range, sort the columns, and tick a box to
include deleted projects.

- **Create and edit.** The client field is a searchable text box. Type an existing client to reuse
  it, or type a new name and it is created when you save. Project names are unique per client, so
  two clients can each have a "Website Redesign".
- **Status.** Changed through a confirmation box, which also records the time. Moving a project to
  In Progress records when it started, and moving it to Completed records when it finished. The time
  defaults to now, and you can change it.
- **Delete and restore.** Deleting hides the project and records who did it. You can restore it from
  the list.
- **Editing at the same time as someone else.** If a colleague saves while you have the form open,
  your save is refused with a message instead of quietly overwriting their work.

**Roles and permissions.** There are six project permissions: `projects.view`, `create`,
`update-status`, `edit-details`, `delete` and `restore`. They are given to roles, never straight to a
person. The seeded **Project Manager** role has all six. The **Super Admin** role has none, because
it passes every check anyway. The Super Admin role and account are marked as protected and cannot be
deleted by anyone.

**API.** The same features over JSON at `/api/v1`, ready for a future frontend. See [API](#api).

## Validation

These are the rules for creating and updating a project. They are written once, in
`app/Validation/ProjectRules.php`, and used by both the pages and the API, so a project saved
through the UI and one sent to the API are held to exactly the same standard. There is no second
copy to fall out of step.

| Field | Rules |
| --- | --- |
| Client Name | Required. Text, up to 255 characters. |
| Project Name | Required. Text, up to 255 characters, and not already used by that client. |
| Description | Optional. Any length. |
| Status | Optional, and must be one of `planning`, `in_progress`, `on_hold`, `completed`. Only accepted when creating. |
| Priority | Optional, and must be one of `low`, `medium`, `high`. |
| Start Date | Required. Must be a real date. |
| Due Date | Optional. Must be a real date, and on or after the Start Date. |

A few of these are worth spelling out:

- **The project name only has to be unique for its client.** Two clients can each have a "Website
  Redesign". Asking the same client for a second one is refused, and so is reusing the name of a
  project that client has deleted, because restoring that project would then create a duplicate.
- **Status and Priority are checked against the list of allowed values**, so a typo or an
  invented value is rejected rather than saved.
- **Due Date is compared against the Start Date in the same request**, so the two are always
  consistent with each other.
- **Editing does not accept a Status.** That happens through the status screen, which has its own
  rules: the status must be a valid one, and the time you give it cannot be earlier than when the
  project started or later than when it was completed.

### What you see when something is wrong

In the UI, the message appears under the field it belongs to and nothing is saved. The wording is
written for the person reading it, not copied from the database. Asking a client for a project name
they already have says:

> This client already has a project with that name. If it was deleted, restore that project instead
> of creating a new one.

The API returns `422` with the same messages, listed by field, so a client can show them next to its
own inputs:

```json
{
  "message": "The client name field is required. (and 2 more errors)",
  "errors": {
    "client_name": ["The client name field is required."],
    "name": ["The name field is required."],
    "start_date": ["The start date field is required."]
  }
}
```

## Technology choices

| Choice | Why |
| --- | --- |
| **Laravel 13** (PHP 8.5) | One of the backend options the brief allows. It already includes most of what this app needs: the database layer, validation, authorization, and first-party login. |
| **Livewire 4 + Flux UI 2** | The pages are rendered by the same PHP that holds the rules, so validation and permissions are written once instead of twice. There is a note on the frontend options below. |
| **SQLite** | Allowed by the brief, and the only option that needs no database server. `composer setup` creates a file and the app runs. The code does not depend on SQLite, so MySQL or PostgreSQL work too. |
| **Fortify** | Gives login, registration, password reset, two-factor and passkeys without writing them by hand. It has no UI of its own, so it does not dictate how the pages look. |
| **spatie/laravel-permission** | The standard package for roles and permissions in Laravel. Writing a weaker version of it would not have been a good use of the time. |
| **Sanctum** | Token login for the API. It is first-party, and it covers both likely futures: tokens for a separate frontend, or cookies for a single-page app on the same domain. OAuth2 would be more machinery than this needs. |
| **Pest 5, Pint, Larastan** | Tests, code formatting and static analysis. All three run together with `composer test`. |

**About the frontend options.** The brief lists React, Vue, Angular and Next.js. This app uses
Livewire, which is Laravel's own way of building pages on the server. The reason is that splitting a
small CRUD app across two codebases means writing the validation and permission rules twice, and
those two copies drift apart over time. The JSON API is fully built and documented, so a React or
Vue frontend can be added later without changing any of the business logic.

## API

A versioned JSON API that covers everything the UI does. The Livewire pages do not call it. They
talk to the server their own way, and both share the same service and validation classes, so neither
one is built on top of the other.

**[`docs/api.md`](docs/api.md) is the full reference.** It lists every endpoint, every parameter,
every request you can make and the exact response you get back, all captured from a running copy of
the app. What follows here is the short version: how to get a token and make your first call.

### Authenticating

Requests use a Sanctum bearer token. There is no endpoint that hands them out. You create one from
the command line, for any user:

```bash
php artisan api:token pm@example.com
```

It prints the user, the roles the token will carry, and the token itself. Sanctum only stores a
hashed copy, so the token is shown once and cannot be looked up later. Copy it when it appears. Add
`--name` to label a token (`php artisan api:token pm@example.com --name=postman`) so you can revoke
that one without affecting the others.

### Trying it in Postman

1. Run `php artisan api:token pm@example.com` and copy the token.
2. In Postman, set the request's **Authorization** type to **Bearer Token** and paste it in. You can
   also add the header yourself as `Authorization: Bearer <token>`.
3. Add an `Accept: application/json` header.
4. `GET http://project-tracker.test/api/v1/projects`

Try a token for `superadmin@example.com` to see every endpoint succeed, then one for an account with
fewer permissions to see the same request come back as `403`.

```bash
curl -H "Authorization: Bearer <token>" -H "Accept: application/json" http://project-tracker.test/api/v1/projects
```

The examples use `http://project-tracker.test`, which is what Herd serves before you run
`herd secure`. Use whatever address you serve the app on: `http://localhost:8000` under
`composer dev`, or an `https://` address once the site is secured. The API works the same either way.

A token has all the permissions its owner has. Treat it like that person's password, and create a
separate token for each integration so you can revoke one without breaking the rest.

The API allows **60 requests per minute per user**. Unauthenticated requests are counted by IP
address instead. Going over returns `429` with a `Retry-After` header.

### Endpoints

Every route needs a valid token and the permission listed.

| Method | URI | Purpose | Permission |
| --- | --- | --- | --- |
| GET | `/api/v1/projects` | List projects, with the same search, filters and sorting as the UI | `projects.view` |
| GET | `/api/v1/projects/{project}` | Read one project | `projects.view` |
| POST | `/api/v1/projects` | Create a project, and its client if the name is new | `projects.create` |
| PUT | `/api/v1/projects/{project}` | Update the details, but not the status | `projects.edit-details` |
| PATCH | `/api/v1/projects/{project}/status` | Change the status and record the time | `projects.update-status` |
| DELETE | `/api/v1/projects/{project}` | Delete a project | `projects.delete` |
| POST | `/api/v1/projects/{project}/restore` | Restore a deleted project | `projects.restore` |

### Listing

`GET /api/v1/projects` takes the same options as the list page, so a client can reproduce any list a
user can see:

| Parameter | Accepts |
| --- | --- |
| `search` | Matches project name, description or client name |
| `status` | `planning`, `in_progress`, `on_hold`, `completed` |
| `priority` | `low`, `medium`, `high` |
| `due_from`, `due_to` | Dates. The range is only applied when you give both. |
| `sort` | `name`, `client_name`, `due_date`, `status`, `priority`, `created_at` |
| `direction` | `asc`, `desc` (default `desc`) |
| `with_deleted` | Boolean. Deleted projects are left out unless this is set. |
| `per_page` | 1–100 (default 25) |

Anything outside these values returns `422` instead of being quietly ignored. The list of sortable
columns matters most: it is what keeps user input out of the `ORDER BY` clause.

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

`status` and `priority` come back with both the value and the label, so you can show one and filter
on the other without keeping your own copy of the wording. When a project has no status or priority,
those keys are left out of the response entirely rather than sent as `null`. `deleted_at` and
`deleted_by` only appear on deleted projects.

### Creating and updating

```bash
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"client_name":"Initech","name":"Intranet","priority":"high","start_date":"2026-09-01","due_date":"2026-12-01"}' \
  http://project-tracker.test/api/v1/projects
```

`client_name` is plain text. An existing client is matched even if the spacing or capitals differ,
and a new name creates a client. Project names are unique per client, so two clients can each have a
"Website Redesign". A deleted project still holds its name, so restoring it can never create a
duplicate.

`PUT` does not accept a status. Status has its own endpoint, because changing it also records a time
and needs a different permission:

```bash
curl -X PATCH -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"status":"in_progress","changed_at":"2026-09-02 09:30:00","version":1}' \
  http://project-tracker.test/api/v1/projects/8/status
```

Moving to In Progress records `started_at`, and moving to Completed records `completed_at`, using the
`changed_at` you send. It can be any date and time, not just now. The check works both ways: you
cannot complete a project before it started, and you cannot start one after it was completed.

### Two people saving at once

Every project has a `version` number. `PUT` and `PATCH` need the version you last read. If someone
else saved first, your write changes nothing and you get a **409**:

```json
{
  "message": "This project was updated by someone else.",
  "version": 2
}
```

The current version is included, so you can read the project again, merge your change into it and
retry. `DELETE` and restore do not need a version, because they do not overwrite any field values.

### Responses

| Status | When |
| --- | --- |
| `200` / `201` / `204` | It worked. `201` when something is created, `204` after a delete. |
| `401` | The token is missing or invalid |
| `403` | The token is valid, but its owner does not have the permission |
| `404` | No such project, or it is deleted and this route does not include deleted ones |
| `409` | Someone else saved first |
| `422` | Validation failed. `errors` lists the problems by field. |
| `429` | Too many requests. Wait for the time in the `Retry-After` header. |

Errors are always JSON, even without an `Accept` header, because everything here is under `api/*`:

```json
{
  "message": "The selected sort is invalid.",
  "errors": { "sort": ["The selected sort is invalid."] }
}
```

## Assumptions

These are decisions the
[brief](https://github.com/koda-devteam-ops/fullstack-developer-assessment/blob/main/REQUIREMENTS.md)
did not cover. Two of them change how one of its endpoints behaves, so those come first.

### Two things that differ from the brief

**`DELETE /projects/:id` hides a project instead of erasing it.** The brief says "Delete project".
Here the row is marked as deleted, along with who deleted it, and it can be restored. It disappears
from every normal list and the API returns `404` for it afterwards, so from the outside it behaves
like a delete. The row itself is still in the table. Losing a client's project history to one
mis-click felt like the worse outcome, and being able to undo it only costs two columns and one
extra route.

**`PUT /projects/:id` does not change the status.** The brief says "Update existing project". Status
goes through `PATCH /projects/:id/status` instead. Keeping it separate means a change to where a
project sits in its lifecycle is always deliberate rather than a side effect of editing a form. It
is easy to audit, because those changes come through one place that also records when they happened.
It is easier to secure, because it has its own permission and can be given to people who should not
be renaming projects. And if the agency later wants rules about which status can follow which, there
is one obvious place to put them. A `status` sent to `PUT` is ignored rather than rejected, and the
rest of the update still applies.

### The data

- **Clients are their own table, not just text on the project.** The brief lists Client Name as a
  field of a project. If each project stores its own copy, "Acme Corp" and "acme corp" become two
  different clients and nobody can list one client's work. The form still takes plain text, matches
  an existing client whatever the spacing or capitals, and only creates a client when the name is
  genuinely new.
- **There is no screen for managing clients.** Clients are created as a side effect of creating a
  project. A full CRUD section for them was not asked for.
- **Project names are unique per client, not across the whole system.** Two projects called
  "Website Redesign" for the same client is almost always a mistake. One for each of two clients is
  normal. A deleted project still counts, so restoring it cannot create a duplicate.
- **Status and Priority are optional. Start Date is required, Due Date is not.** The brief requires
  Client Name and Project Name, and says Status and Priority must be valid. That is a rule about the
  value when one is given, not a rule that one must be given. Logging a project before anyone has
  decided its priority is normal.

### Three columns the brief does not list

The brief's purpose is to "track client projects, monitor progress, and manage priorities". Three
columns were added because the fields it does list cannot answer the questions that come with that.

**`started_at` and `completed_at` — when work actually happened.**

Start Date and Due Date are the plan: what someone intended when the project was set up. They never
change on their own, so on their own they cannot tell you how the work really went. `started_at` is
recorded when a project moves to In Progress, and `completed_at` when it moves to Completed.

With both, a manager can answer the questions the plan alone leaves open:

- Did this project start when we said it would, or two weeks late?
- Did it finish before its due date or after it?
- How long did it actually take, and how does that compare with similar projects?
- Which projects have genuinely been worked on, and which have only ever been planned?

The status field cannot stand in for this, because it only tells you where a project is now, not
when it got there. Neither can `updated_at`, because that moves every time anyone edits anything,
including fixing a typo in the description. The time is editable when you change the status, so
someone updating the tracker on Monday can record that work actually started on Friday.

**`version` — so tracked information is not quietly lost.**

Tracking is only useful if the record is right. Without this column, two managers opening the same
project means the second one to save overwrites the first, and nothing anywhere shows that it
happened. The first manager's update is simply gone, and the project now shows something nobody
agreed on.

Each project carries a number that goes up by one each time its details or status are saved. When you
read a project you get its current number, and you send that number back when you save. If it still
matches, your change is written. If someone else saved in the meantime, your save is refused: the UI
keeps what you typed and tells you to reload, and the API returns `409` along with the current number
so a client can read the project again, merge its change in and retry. Nothing is overwritten without
someone being told.

### The API

- **Endpoints sit under `/api/v1` rather than at the root.** Adding a version now costs one path
  segment. Adding one after other people are using the API means breaking their code.
- **`GET /projects` returns a page at a time**, 25 by default and at most 100. The brief says
  "Retrieve all projects", which is fine with nine of them and a problem with fifty thousand. The
  `meta` and `links` in the response let a client walk through the whole set.
- **Every endpoint needs a login and a specific permission.** The brief lists authentication as a
  bonus. Rather than a single "is this person logged in" check, there are six permissions, one for
  each thing you can do to a project. Roles and permissions add accountability, because every action
  is tied to a named permission and you can see exactly which roles hold it. They also keep the
  authorization rules in one place instead of scattered through the code, and the same six
  permissions are used by both the pages and the API, so the two cannot disagree about who is
  allowed to do what.
- **60 requests per minute per user.** Not asked for. A token that leaks can be replayed as fast as
  the network allows, and every other input to the app is already limited.
- **Sort columns come from a fixed list.** A column name cannot be safely passed into SQL as a
  parameter, so an unrecognised one returns `422` on the API and falls back to the default order in
  the UI.
- **Tokens are not limited to particular abilities.** A token can do everything its owner can. There
  is no real integration to model yet, so any limits chosen now would be guesses. It is written down
  here so it is a known trade-off rather than a surprise later.

### How it behaves

- **Any status can follow any other.** The brief lists four statuses, not an order to move through
  them, so nothing is blocked. That is also why the time check works in both directions: a project
  cannot be completed before it started, and it cannot be started after it was completed.
- **"All projects" means all the ones that are not deleted.** Deleted projects only show up when you
  tick the box in the UI, or send `?with_deleted=1` to the API.
- **The app comes with sample data**: three clients, nine projects, and the two accounts above, so it
  is not empty the first time you open it. None of it is seeded in production.

### What was left out

Included from the bonus list: search, filtering by status and priority, sorting, authentication, and
tests covering the business logic, both the pages and the API, and who is allowed to do what. Run
them with `composer test`.

Left out from the bonus list: Docker and deployment. The brief asks for quality over quantity, and a
Docker setup nobody had actually run end to end would be more of a liability than a feature.
`composer setup` is the path that has been tested.

## Tests and checks

```bash
composer test
```

This runs Pint (code formatting), Larastan (static analysis at level 7) and the Pest test suite
together. It is the same check run before every commit. To run them one at a time:

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
| `app/Validation` | `ProjectRules`, every validation rule, shared by the pages and the API |
| `app/Services` | `ProjectService`, the business logic both of them call |
| `app/Livewire/Forms` | Livewire form objects, which check permissions and validate |
| `app/Console/Commands` | `api:token`, which creates an API token for a user |
| `app/Http/Controllers/Api/V1` | API controllers, kept thin and handing the work to the service |
| `app/Http/Requests/Api/V1` | Form requests, each one passing through to `ProjectRules` |
| `app/Http/Resources` | `ProjectResource`, the shape of every API response |
| `resources/views/pages` | Livewire page components (`⚡name.blade.php`) |
| `.ai/rules` | Project conventions, each scoped to the files it applies to |

## Documentation

[`docs/api.md`](docs/api.md) is the API reference. It covers every endpoint, every request you can
make, and the response you get back.

[`docs/implementation-plan.md`](docs/implementation-plan.md) is the design record. It covers the data
model, the structure of the code, and the reasoning behind each decision: why the validation rules
live in one shared class, how two people creating the same client at the same time is handled, why
version numbers are there, and what was deliberately left out.
