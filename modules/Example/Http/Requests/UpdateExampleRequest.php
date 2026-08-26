<?php

declare(strict_types=1);

namespace Modules\Example\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Example\Enums\ExampleStatus;

class UpdateExampleRequest extends FormRequest
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
        $exampleId = $this->route('example');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('examples', 'slug')->ignore($exampleId)],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(ExampleStatus::class)],
            'is_featured' => ['boolean'],
        ];
    }
}
