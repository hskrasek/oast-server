<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Identity\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

final class BootstrapInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->get('oast.setup.authorized') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'organization_name' => ['required', 'string', 'max:255'],
            'password' => PasswordRules::confirmed(),
        ];
    }
}
