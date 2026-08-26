<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Illuminate\Contracts\Validation\Rule;

class UniqueVerifiedContact implements Rule
{
    protected string $field;

    protected ?string $message = null;

    public function __construct(string $field)
    {
        $this->field = $field;
    }

    public function passes($attribute, $value): bool
    {
        if (empty($value)) {
            return true;
        }

        $existingUser = User::where($this->field, $value)->first();

        if (! $existingUser) {
            return true;
        }

        $isPrimaryContact = ($this->field === 'email' && empty($existingUser->phone))
                         || ($this->field === 'phone' && empty($existingUser->email));

        if ($isPrimaryContact) {
            $this->message = __("validation.custom.{$this->field}.unique_verified_contact.taken");

            return false;
        }

        $verifiedAtField = $this->field.'_verified_at';

        if ($existingUser->$verifiedAtField) {
            $this->message = __("validation.custom.{$this->field}.unique_verified_contact.verified");

            return false;
        }

        return true;
    }

    public function message(): string
    {
        return $this->message ?? __('validation.unique');
    }
}
