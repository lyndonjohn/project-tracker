<?php

namespace App\Exceptions;

use App\Models\Project;
use RuntimeException;

/**
 * Thrown when a project moved on between being loaded and being saved.
 *
 * The write is refused rather than silently overwriting whoever saved first. The current
 * row is carried along so the UI can offer to reload it and the API can return its
 * version with the 409. It is null when the row no longer exists at all — a concurrent
 * delete, for instance.
 */
class StaleProjectException extends RuntimeException
{
    public function __construct(public readonly ?Project $current = null)
    {
        parent::__construct('This project was updated by someone else.');
    }
}
