---
paths:
  - 'app/Services/**'
---

# Services

## Don't wrap firstOrCreate in a catch for unique violations
Eloquent's `firstOrCreate()` already defers to `createOrFirst()`, which wraps the insert in a savepoint, catches `UniqueConstraintViolationException`, and re-reads the winning row over the write PDO. Adding an outer try/catch (as `ProjectService::resolveClient()` originally did) duplicates that and can only fire when the framework's own re-select already missed — where a second select would miss too, and the replacement exception is less informative than the original. Rely on the unique index plus `firstOrCreate`.

Corollary for tests: a race cannot be simulated by inserting a conflicting row from a model `creating` hook. `createOrFirst` opens a savepoint first, so the violation rolls that insert back and the recovery select finds nothing. Assert the observable guarantee instead (differently spelled names converge on one row) and let the index cover the rest.
