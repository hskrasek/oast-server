<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Identity\PasswordRules;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<Rule|ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:web'],
            'password' => PasswordRules::confirmed(),
        ];
    }
}
