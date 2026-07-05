<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubscribeRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'website' => ['nullable', 'string'],
        ];
    }

    public function isSpam(): bool
    {
        return filled($this->input('website'));
    }
}
