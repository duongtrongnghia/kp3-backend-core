<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization handled by route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $ids = $this->input('ids', []);
            if (in_array(auth()->id(), (array) $ids, false)) {
                $v->errors()->add('ids', __('api.user.cannot_delete_self'));
            }
        });
    }
}
