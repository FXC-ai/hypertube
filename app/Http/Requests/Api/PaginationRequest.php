<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaginationMeta',
    type: 'object',
    description: 'How a paginated listing is spread over its pages.',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer'),
        new OA\Property(property: 'from', type: 'integer', nullable: true),
        new OA\Property(property: 'last_page', type: 'integer'),
        new OA\Property(property: 'per_page', type: 'integer'),
        new OA\Property(property: 'to', type: 'integer', nullable: true),
        new OA\Property(property: 'total', type: 'integer'),
    ],
)]
#[OA\Schema(
    schema: 'PaginationLinks',
    type: 'object',
    description: 'The links of a paginated listing: follow first_page to go back, next to go forward.',
    properties: [
        new OA\Property(property: 'first', type: 'string', format: 'uri'),
        new OA\Property(property: 'last', type: 'string', format: 'uri'),
        new OA\Property(property: 'prev', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'next', type: 'string', format: 'uri', nullable: true),
    ],
)]
class PaginationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * How many rows a page of a listing carries.
     */
    public function perPage(int $default = 20): int
    {
        return (int) ($this->validated()['perPage'] ?? $default);
    }
}
