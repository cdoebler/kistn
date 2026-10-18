<?php

namespace App\Http\Requests\Api;

use App\Enums\EcosystemEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class WpPreflightRequest extends FormRequest
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
        return [
            'packages' => ['required', 'array', 'max:'.Config::integer('inventory.payload_limits.packages')],
            'packages.*.ecosystem' => ['required', 'string', Rule::in([
                EcosystemEnum::WpCore->value,
                EcosystemEnum::WpPlugin->value,
                EcosystemEnum::WpTheme->value,
            ])],
            'packages.*.name' => ['required', 'string', 'max:255'],
            'packages.*.version' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, array{ecosystem: string, name: string, version: string}>
     */
    public function packages(): array
    {
        /** @var array<int, array{ecosystem: string, name: string, version: string}> */
        return $this->validated('packages', []);
    }
}
