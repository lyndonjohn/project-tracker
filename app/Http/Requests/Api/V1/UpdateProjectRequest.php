<?php

namespace App\Http\Requests\Api\V1;

use App\Validation\ProjectRules;

/**
 * Status is not accepted here — it moves through the status endpoint, which also stamps a
 * timestamp and carries its own permission. ProjectRules::details() leaves the rule out
 * once a project is passed, so a status sent here is simply not applied.
 */
class UpdateProjectRequest extends ProjectRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...ProjectRules::details($this->stringOrNull('client_name'), $this->project()),
            ...ProjectRules::version(),
        ];
    }
}
