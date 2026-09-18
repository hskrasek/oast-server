<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DesignTurnRequest extends FormRequest
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
            'spec' => ['present', 'string', 'max:1048576'],
            'message' => ['required', 'string', 'max:16384'],
            'history' => ['sometimes', 'array'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required', 'string', 'max:16384'],
        ];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function history(): array
    {
        /** @var array<int, array{role: string, content: string}> $history */
        $history = $this->validated('history', []);

        return array_values($history);
    }

    /**
     * The global ConvertEmptyStringsToNull middleware turns an empty `spec`
     * (turn one, no draft yet) into null before validation runs; put it back
     * so `present`/`string` see the empty string the client actually sent.
     * Only when the key was sent at all — a wholly missing `spec` must stay
     * missing so `present` still rejects it.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('spec')) {
            $this->merge(['spec' => $this->input('spec') ?? '']);
        }
    }
}
