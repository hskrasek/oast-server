<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Council\ReviewMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'spec' => ['required', 'string'],
            'mode' => ['nullable', Rule::enum(ReviewMode::class)],
        ];
    }
}
