# Project Tracker API — v1 reference

Every request and response below was captured from a running instance with `APP_DEBUG=false`, so
the bodies are what the API actually returns in a deployed environment, not illustrations.

- **Base URL:** `{host}/api/v1` — the host is wherever you serve the app (`http://project-tracker.test`
  under Herd, `http://localhost:8000` under `composer dev`). Nothing in the API depends on which.
- **Every endpoint** requires a bearer token *and* a permission. See [Authentication](#authentication).
- **Every response** is JSON, including errors, regardless of the `Accept` header.

## Contents

- [Authentication](#authentication)
- [Rate limiting](#rate-limiting)
- [Conventions](#conventions)
- [Errors](#errors)
- [List projects](#list-projects)
- [Read a project](#read-a-project)
- [Create a project](#create-a-project)
- [Update a project](#update-a-project)
- [Change a project's status](#change-a-projects-status)
- [Delete a project](#delete-a-project)
- [Restore a project](#restore-a-project)
- [Optimistic locking](#optimistic-locking)

## Authentication

Issue a token from the console — there is no token endpoint:

```bash
php artisan api:token pm@example.com
```

Send it as a bearer token:

```
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json    # on POST, PUT and PATCH
```

A token **carries every permission its owner holds** — abilities are not scoped, so a token is
equivalent to that user's password. Issue one per integration so any of them can be revoked alone.

| Case | Status | Body |
| --- | --- | --- |
| No `Authorization` header | `401` | `{"message": "Unauthenticated."}` |
| Malformed or revoked token | `401` | `{"message": "Unauthenticated."}` |
| Valid token, missing permission | `403` | `{"message": "This action is unauthorized."}` |

The `401` is returned even without an `Accept: application/json` header — everything under `api/*`
renders as JSON.

## Rate limiting

60 requests per minute, counted **per user** (falling back to the IP address for unauthenticated
requests), so several people behind one office NAT do not share a bucket.

Every response carries the current state:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 54
```

Once exhausted:

```
HTTP/1.1 429 Too Many Requests
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
Retry-After: 58
X-RateLimit-Reset: 1786363559
```

```json
{ "message": "Too Many Attempts." }
```

## Conventions

**Envelope.** A single project is wrapped in `data`. A list adds `links` and `meta`.

**The project object:**

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `name` | string | Unique per client |
| `description` | string or null | |
| `client` | object | `{ "id": 4, "name": "Docs Client" }` |
| `status` | object | `{ "value": "planning", "label": "Planning" }` — **key absent when unset** |
| `priority` | object | `{ "value": "high", "label": "High" }` — **key absent when unset** |
| `start_date` | date | `YYYY-MM-DD` |
| `due_date` | date or null | `YYYY-MM-DD` |
| `started_at` | datetime or null | ISO 8601; stamped when the status becomes In Progress |
| `completed_at` | datetime or null | ISO 8601; stamped when the status becomes Completed |
| `version` | integer | Send it back on the next write — see [Optimistic locking](#optimistic-locking) |
| `created_at`, `updated_at` | datetime | ISO 8601 |
| `deleted_at`, `deleted_by` | datetime, integer | **Only present on deleted projects** |

`status` and `priority` are **omitted entirely** rather than sent as `null` when the project has
none. A client reading `project.status.value` must check the key exists first.

**Enum values** (what you send) and their labels (what to display):

| `status` | Label | | `priority` | Label |
| --- | --- | --- | --- | --- |
| `planning` | Planning | | `low` | Low |
| `in_progress` | In progress | | `medium` | Medium |
| `on_hold` | On hold | | `high` | High |
| `completed` | Completed | | | |

## Errors

| Status | Meaning | Shape |
| --- | --- | --- |
| `401` | No valid token | `{"message": "Unauthenticated."}` |
| `403` | Token valid, owner lacks the permission | `{"message": "This action is unauthorized."}` |
| `404` | No such project, or it is deleted and the route excludes deleted ones | `{"message": "No query results for model [App\\Models\\Project] 99999"}` |
| `409` | Version conflict | `{"message": "...", "version": 2}` |
| `422` | Validation failed | `message` plus `errors` keyed by field |
| `429` | Rate limited | `{"message": "Too Many Attempts."}` |

A `422` names the first problem in `message` and lists them all in `errors`:

```json
{
  "message": "The selected status is invalid. (and 5 more errors)",
  "errors": {
    "status": ["The selected status is invalid."],
    "priority": ["The selected priority is invalid."],
    "sort": ["The selected sort is invalid."],
    "direction": ["The selected direction is invalid."],
    "with_deleted": ["The include deleted field must be true or false."],
    "per_page": ["The page size field must be between 1 and 100."]
  }
}
```

---

## List projects

```
GET /api/v1/projects
```

Permission: `projects.view`

### Query parameters

All optional. Anything outside these values is a `422`, never a silently ignored parameter.

| Parameter | Accepts | Default |
| --- | --- | --- |
| `search` | string, max 255 — matches project name, description, or client name | — |
| `status` | `planning`, `in_progress`, `on_hold`, `completed` | all |
| `priority` | `low`, `medium`, `high` | all |
| `due_from` | date — applied **only** when `due_to` is also given | — |
| `due_to` | date — applied **only** when `due_from` is also given | — |
| `sort` | `name`, `client_name`, `due_date`, `status`, `priority`, `created_at` | `created_at` |
| `direction` | `asc`, `desc` | `desc` |
| `with_deleted` | boolean — deleted projects are excluded unless set | `false` |
| `per_page` | integer 1–100 | `25` |

### Requests and results

| Request | Result |
| --- | --- |
| `GET /projects` | `200` — first 25, newest first |
| `GET /projects?per_page=2` | `200` — 2 per page |
| `GET /projects?sort=name&direction=asc` | `200` — sorted by name |
| `GET /projects?sort=client_name` | `200` — sorted by the client's name |
| `GET /projects?search=Globex` | `200` — name, description or client matches |
| `GET /projects?status=completed` | `200` — filtered |
| `GET /projects?priority=high` | `200` — filtered |
| `GET /projects?due_from=2026-01-01&due_to=2026-12-31` | `200` — due within the range |
| `GET /projects?due_from=2026-01-01` | `200` — **range ignored**, both bounds are required |
| `GET /projects?with_deleted=1` | `200` — deleted projects included, each with `deleted_at`/`deleted_by` |
| `GET /projects?sort=password` | `422` — not a sortable column |
| `GET /projects?sort=name);drop table projects;--` | `422` — same whitelist |
| `GET /projects?direction=sideways` | `422` |
| `GET /projects?per_page=101` | `422` — above the cap |
| `GET /projects?per_page=0` | `422` — below the cap |
| `GET /projects?status=abandoned` | `422` — not a status |
| `GET /projects?priority=urgent` | `422` — not a priority |
| `GET /projects?with_deleted=maybe` | `422` — not a boolean |
| Without a token | `401` |
| Token whose owner lacks `projects.view` | `403` |

### `200` response

```json
{
  "data": [
    {
      "id": 1,
      "name": "Neque Voluptas",
      "description": "Rerum at cum harum fugit.",
      "client": { "id": 1, "name": "Acme Corporation" },
      "status": { "value": "planning", "label": "Planning" },
      "priority": { "value": "low", "label": "Low" },
      "start_date": "2026-07-20",
      "due_date": "2026-09-03",
      "started_at": null,
      "completed_at": null,
      "version": 1,
      "created_at": "2026-08-10T12:04:19+00:00",
      "updated_at": "2026-08-10T12:04:19+00:00"
    }
  ],
  "links": {
    "first": "http://project-tracker.test/api/v1/projects?per_page=2&page=1",
    "last": "http://project-tracker.test/api/v1/projects?per_page=2&page=5",
    "prev": null,
    "next": "http://project-tracker.test/api/v1/projects?per_page=2&page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "to": 2,
    "last_page": 5,
    "per_page": 2,
    "total": 9,
    "path": "http://project-tracker.test/api/v1/projects",
    "links": []
  }
}
```

A deleted project in the list carries two extra keys:

```json
{
  "id": 11,
  "name": "Full Project",
  "version": 1,
  "deleted_at": "2026-08-10T12:04:59+00:00",
  "deleted_by": 2
}
```

### `422` response

```json
{
  "message": "The selected sort is invalid.",
  "errors": { "sort": ["The selected sort is invalid."] }
}
```

---

## Read a project

```
GET /api/v1/projects/{project}
```

Permission: `projects.view`

| Request | Result |
| --- | --- |
| `GET /projects/1` | `200` |
| `GET /projects/99999` | `404` |
| `GET /projects/{deleted id}` | `404` — this route never resolves deleted projects; list them with `?with_deleted=1` |
| Without a token | `401` |
| Token lacking `projects.view` | `403` |

### `200` response

```json
{
  "data": {
    "id": 1,
    "name": "Neque Voluptas",
    "description": "Rerum at cum harum fugit.",
    "client": { "id": 1, "name": "Acme Corporation" },
    "status": { "value": "planning", "label": "Planning" },
    "priority": { "value": "low", "label": "Low" },
    "start_date": "2026-07-20",
    "due_date": "2026-09-03",
    "started_at": null,
    "completed_at": null,
    "version": 1,
    "created_at": "2026-08-10T12:04:19+00:00",
    "updated_at": "2026-08-10T12:04:19+00:00"
  }
}
```

---

## Create a project

```
POST /api/v1/projects
```

Permission: `projects.create`

### Body

| Field | Rules |
| --- | --- |
| `client_name` | **required**, string, max 255 — matched case- and whitespace-insensitively against existing clients; an unknown name creates one |
| `name` | **required**, string, max 255, unique for that client (deleted projects still hold their name) |
| `description` | optional, string |
| `status` | optional — a status enum value; accepted on create only |
| `priority` | optional — a priority enum value |
| `start_date` | **required**, date |
| `due_date` | optional, date, on or after `start_date` |

### Requests and results

| Request body | Result |
| --- | --- |
| `client_name`, `name`, `start_date` | `201` — the minimum |
| All fields above | `201` |
| `"client_name": "  docs   CLIENT "` where "Docs Client" exists | `201` — reuses that client, no duplicate created |
| `client_name` naming a new client | `201` — the client is created with the project |
| `{}` | `422` — `client_name`, `name` and `start_date` all required |
| `due_date` before `start_date` | `422` on `due_date` |
| `name` already used by that client | `422` on `name` |
| `name` used by a **deleted** project of that client | `422` on `name` — restore it instead |
| Same `name` under a **different** client | `201` — names are unique per client, not globally |
| `"client_name": ["Acme"]` | `422` on `client_name`, not a server error |
| `"status": "abandoned"`, `"priority": "urgent"` | `422` on both |
| Without a token | `401` |
| Token lacking `projects.create` | `403` |

```bash
curl -X POST http://project-tracker.test/api/v1/projects \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"client_name":"Docs Client","name":"Full Project","description":"Every field supplied.","status":"planning","priority":"high","start_date":"2026-09-01","due_date":"2026-12-01"}'
```

### `201` response

```json
{
  "data": {
    "id": 11,
    "name": "Full Project",
    "description": "Every field supplied.",
    "client": { "id": 4, "name": "Docs Client" },
    "status": { "value": "planning", "label": "Planning" },
    "priority": { "value": "high", "label": "High" },
    "start_date": "2026-09-01",
    "due_date": "2026-12-01",
    "started_at": null,
    "completed_at": null,
    "version": 1,
    "created_at": "2026-08-10T12:04:58+00:00",
    "updated_at": "2026-08-10T12:04:58+00:00"
  }
}
```

Created with only the required fields, `status` and `priority` are absent rather than null:

```json
{
  "data": {
    "id": 10,
    "name": "Minimal Project",
    "description": null,
    "client": { "id": 4, "name": "Docs Client" },
    "start_date": "2026-09-01",
    "due_date": null,
    "started_at": null,
    "completed_at": null,
    "version": 1,
    "created_at": "2026-08-10T12:04:58+00:00",
    "updated_at": "2026-08-10T12:04:58+00:00"
  }
}
```

### `422` responses

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

```json
{
  "message": "This client already has a project with that name. If it was deleted, restore that project instead of creating a new one.",
  "errors": {
    "name": ["This client already has a project with that name. If it was deleted, restore that project instead of creating a new one."]
  }
}
```

```json
{
  "message": "The due date must be on or after the start date.",
  "errors": { "due_date": ["The due date must be on or after the start date."] }
}
```

---

## Update a project

```
PUT /api/v1/projects/{project}
```

Permission: `projects.edit-details`

Same fields as create, plus a required `version`. **`status` is not accepted here** — it moves
through the status endpoint, which also stamps a timestamp and carries its own permission. A
`status` in this body is ignored, not an error.

| Field | Rules |
| --- | --- |
| `client_name`, `name`, `description`, `priority`, `start_date`, `due_date` | as [create](#create-a-project) |
| `version` | **required**, integer ≥ 1 — the version you last read |

### Requests and results

| Request | Result |
| --- | --- |
| Valid body with the current `version` | `200` — `version` comes back incremented |
| Changing `client_name` to another client | `200` — the project moves to that client |
| Body without `version` | `422` on `version` |
| `version` older than the stored one | `409` — nothing is written |
| `name` already used by that client | `422` on `name` |
| `due_date` before `start_date` | `422` on `due_date` |
| Includes `"status": "completed"` | `200`, and the status is **unchanged** |
| Unknown project id | `404` |
| Without a token | `401` |
| Token lacking `projects.edit-details` | `403` |

### `200` response

```json
{
  "data": {
    "id": 10,
    "name": "Minimal Project Renamed",
    "description": null,
    "client": { "id": 4, "name": "Docs Client" },
    "priority": { "value": "medium", "label": "Medium" },
    "start_date": "2026-09-15",
    "due_date": "2026-11-01",
    "started_at": null,
    "completed_at": null,
    "version": 2,
    "created_at": "2026-08-10T12:04:58+00:00",
    "updated_at": "2026-08-10T12:04:59+00:00"
  }
}
```

### `422` and `409`

```json
{ "message": "The version field is required.", "errors": { "version": ["The version field is required."] } }
```

```json
{ "message": "This project was updated by someone else.", "version": 2 }
```

---

## Change a project's status

```
PATCH /api/v1/projects/{project}/status
```

Permission: `projects.update-status`

| Field | Rules |
| --- | --- |
| `status` | **required** — a status enum value |
| `changed_at` | **required**, datetime — bounded, see below |
| `version` | **required**, integer ≥ 1 |

Moving to `in_progress` stamps `started_at` with `changed_at`; moving to `completed` stamps
`completed_at`. Any status may follow any other — there is no workflow — so the bound on
`changed_at` is symmetric:

- Completing a project that has a `started_at`: `changed_at` must be **on or after** it.
- Starting a project that already has a `completed_at`: `changed_at` must be **on or before** it.

### Requests and results

| Request | Result |
| --- | --- |
| `{"status":"in_progress","changed_at":"2026-09-16 09:30:00","version":2}` | `200` — `started_at` set to that time |
| `{"status":"completed","changed_at":"2026-10-30 17:00:00","version":3}` | `200` — `completed_at` set |
| `{"status":"on_hold",...}` | `200` — status changes, no timestamp written |
| `{"status":"planning",...}` on a completed project | `200` — earlier timestamps are kept |
| Completing with `changed_at` before `started_at` | `422` on `changed_at` |
| Starting with `changed_at` after `completed_at` | `422` on `changed_at` |
| `"status": "abandoned"` | `422` on `status` |
| Missing `version` | `422` on `version` |
| Stale `version` | `409` |
| Unknown project id | `404` |
| Without a token | `401` |
| Token lacking `projects.update-status` | `403` |

### `200` response

```json
{
  "data": {
    "id": 10,
    "name": "Minimal Project Renamed",
    "client": { "id": 4, "name": "Docs Client" },
    "status": { "value": "in_progress", "label": "In progress" },
    "priority": { "value": "medium", "label": "Medium" },
    "start_date": "2026-09-15",
    "due_date": "2026-11-01",
    "started_at": "2026-09-16T09:30:00+00:00",
    "completed_at": null,
    "version": 3,
    "created_at": "2026-08-10T12:04:58+00:00",
    "updated_at": "2026-08-10T12:04:59+00:00"
  }
}
```

### `422` responses

```json
{
  "message": "The completion time must be on or after the project was started.",
  "errors": { "changed_at": ["The completion time must be on or after the project was started."] }
}
```

```json
{
  "message": "The start time must be on or before the project was completed.",
  "errors": { "changed_at": ["The start time must be on or before the project was completed."] }
}
```

---

## Delete a project

```
DELETE /api/v1/projects/{project}
```

Permission: `projects.delete`

Soft delete. The row stays, keeps its name, and records who deleted it in `deleted_by`.

| Request | Result |
| --- | --- |
| `DELETE /projects/11` | `204` — no body |
| The same request again | `404` — already deleted, and this route does not resolve deleted projects |
| Unknown project id | `404` |
| Without a token | `401` |
| Token lacking `projects.delete` | `403` |

No `version` is required: a delete does not overwrite field values, so a caller working from a
slightly stale list still deletes the project they meant to.

---

## Restore a project

```
POST /api/v1/projects/{project}/restore
```

Permission: `projects.restore`

| Request | Result |
| --- | --- |
| `POST /projects/11/restore` on a deleted project | `200` — restored, `deleted_by` cleared |
| The same request on a live project | `200` — no change |
| Unknown project id | `404` |
| Without a token | `401` |
| Token lacking `projects.restore` | `403` |

### `200` response

The restored project, with `deleted_at` and `deleted_by` now absent:

```json
{
  "data": {
    "id": 11,
    "name": "Full Project",
    "description": "Every field supplied.",
    "client": { "id": 4, "name": "Docs Client" },
    "status": { "value": "planning", "label": "Planning" },
    "priority": { "value": "high", "label": "High" },
    "start_date": "2026-09-01",
    "due_date": "2026-12-01",
    "started_at": null,
    "completed_at": null,
    "version": 1,
    "created_at": "2026-08-10T12:04:58+00:00",
    "updated_at": "2026-08-10T12:05:00+00:00"
  }
}
```

---

## Optimistic locking

Two people editing the same project would otherwise mean the second save silently overwrites the
first, with nobody told. Every project carries a `version`, and `PUT` and `PATCH` require the one you
last read.

1. Read a project — note its `version`.
2. Send that `version` with your write.
3. If it still matches, the write lands and `version` increments.
4. If someone else wrote first, nothing changes and you get a `409` with the current version:

```json
{ "message": "This project was updated by someone else.", "version": 2 }
```

Re-read the project, merge your change into what is there now, and send again with the new version.
The `version` in the `409` body tells you what to expect without a second request.

`DELETE` and restore take no version, by design — see the note under [Delete](#delete-a-project).
