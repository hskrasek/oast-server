<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class TransferOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // No `exists` rule: a global existence check answers differently for
        // unknown IDs (422) than for other organizations' memberships (404),
        // leaking which IDs exist. The controller's org-scoped findOrFail
        // returns the same 404 for both.
        return ['membership_id' => ['required', 'integer']];
    }
}
