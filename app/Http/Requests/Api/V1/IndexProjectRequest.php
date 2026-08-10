<?php

namespace App\Http\Requests\Api\V1;

use App\Validation\ProjectRules;

class IndexProjectRequest extends ProjectRequest
{
    public const int DEFAULT_PER_PAGE = 25;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ProjectRules::listing();
    }

    /**
     * Unlike the index page, which resets a bad value and renders the list anyway, an
     * unusable query parameter here is a 422 from the rules above — an API client can act
     * on being told what it sent was wrong, where a person following a stale bookmark
     * cannot.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            ...$this->safe()->except('with_deleted', 'per_page'),
            'with_deleted' => $this->boolean('with_deleted'),
        ];
    }

    public function perPage(): int
    {
        return $this->validated('per_page') ?? self::DEFAULT_PER_PAGE;
    }
}
