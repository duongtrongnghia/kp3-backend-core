<?php

declare(strict_types=1);

namespace Modules\Example\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Example\Enums\ExampleStatus;

class StoreExampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:examples,slug'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(ExampleStatus::class)],
            'is_featured' => ['boolean'],
            'meta' => ['array'],
        ];
    }
}
