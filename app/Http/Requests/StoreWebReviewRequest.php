<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Council\Dimension;
use App\Council\ReviewMode;
use App\Reviews\ValidSpec;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreWebReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'spec' => ['nullable', 'string', 'required_without:spec_file', 'prohibits:spec_file', new ValidSpec],
            'spec_file' => ['nullable', 'file', 'max:5120', 'required_without:spec', 'prohibits:spec'],
            'mode' => ['required', Rule::enum(ReviewMode::class)],
            'dimension' => ['required', Rule::enum(Dimension::class)],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $upload = $this->file('spec_file');
                if ($validator->errors()->isEmpty() && $upload instanceof UploadedFile) {
                    $content = validator(['spec_file' => $upload->getContent()], ['spec_file' => [new ValidSpec]]);
                    $validator->errors()->merge($content->errors()->messages());
                }
            },
        ];
    }

    public function spec(): string
    {
        $upload = $this->file('spec_file');

        return $upload instanceof UploadedFile ? $upload->getContent() : $this->string('spec')->value();
    }

    public function specRef(): ?string
    {
        $upload = $this->file('spec_file');

        return $upload instanceof UploadedFile ? $upload->getClientOriginalName() : null;
    }

    public function mode(): ReviewMode
    {
        return ReviewMode::from($this->string('mode')->value());
    }

    public function dimension(): Dimension
    {
        return Dimension::from($this->string('dimension')->value());
    }
}
