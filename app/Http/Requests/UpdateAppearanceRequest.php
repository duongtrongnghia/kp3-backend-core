<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'theme_mode' => ['sometimes', 'string', 'in:light,dark,system'],
            'theme_layout' => ['sometimes', 'string', 'in:main-layout,sideblock'],
            'card_skin' => ['sometimes', 'string', 'in:bordered,shadow'],
            'is_monochrome' => ['sometimes', 'boolean'],
            'dark_color_scheme' => ['sometimes', 'string', 'in:cinder,navy,mirage,black,mint'],
            'light_color_scheme' => ['sometimes', 'string', 'in:slate,gray,neutral'],
            'primary_color_scheme' => ['sometimes', 'string', 'in:indigo,blue,green,amber,purple,rose'],
            'notification_position' => ['sometimes', 'string', 'in:top-left,top-center,top-right,bottom-left,bottom-center,bottom-right'],
            'notification_expanded' => ['sometimes', 'boolean'],
            'notification_visible_toasts' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }
}
