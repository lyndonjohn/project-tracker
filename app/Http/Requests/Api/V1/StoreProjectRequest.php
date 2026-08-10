<?php

namespace App\Http\Requests\Api\V1;

use App\Validation\ProjectRules;

class StoreProjectRequest extends ProjectRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ProjectRules::details($this->stringOrNull('client_name'));
    }
}
