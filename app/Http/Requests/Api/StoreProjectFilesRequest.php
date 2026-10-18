<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'installed_json' => ['sometimes', 'file'],
            'composer_lock' => ['sometimes', 'file'],
            'composer_json' => ['sometimes', 'file'],
            'package_lock' => ['sometimes', 'file'],
            'package_json' => ['sometimes', 'file'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (empty($this->allFiles())) {
                $v->errors()->add('files', 'At least one file must be provided.');
            }
        });
    }
}
