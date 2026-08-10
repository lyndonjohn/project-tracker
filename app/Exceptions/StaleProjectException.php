<?php

namespace App\Exceptions;

use App\Models\Project;
use RuntimeException;

/**
 * Thrown when a project moved on between being loaded and being saved.
 *
 * Carries the current row so the UI can offer to reload and the API can return its
 * version with the 409. Null when the row is gone, as after a concurrent delete.
 */
class StaleProjectException extends RuntimeException
{
    public function __construct(public readonly ?Project $current = null)
    {
        parent::__construct('This project was updated by someone else.');
    }
}
