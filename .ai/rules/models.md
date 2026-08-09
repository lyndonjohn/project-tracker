---
paths:
  - 'app/Models/**'
---

# Models

## Mirror database column defaults in $attributes
A DB-level `default` is not visible to a freshly created model — Eloquent does not re-read the row after an insert, so the attribute is null in memory. `Project::$attributes` therefore sets `version => 1` to match the column default, so the optimistic-locking counter is populated right after `create()` and the first edit has a version to send back. Do the same for any new column with a default that application code reads.
