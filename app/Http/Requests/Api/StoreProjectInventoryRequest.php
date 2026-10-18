<?php

namespace App\Http\Requests\Api;

use App\Enums\EcosystemEnum;
use App\Enums\SeverityEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class StoreProjectInventoryRequest extends FormRequest
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
            'ecosystems' => ['required', 'array'],
            'ecosystems.*' => ['array'],
            'ecosystems.*.packages' => ['present', 'array', 'max:'.Config::integer('inventory.payload_limits.packages')],
            'ecosystems.*.packages.*.name' => ['required', 'string', 'max:255'],
            'ecosystems.*.packages.*.version' => ['required', 'string', 'max:255'],
            'ecosystems.*.packages.*.is_direct' => ['required', 'boolean'],
            'ecosystems.*.packages.*.is_dev' => ['required', 'boolean'],
            'ecosystems.*.packages.*.is_active' => ['nullable', 'boolean'],
            'ecosystems.*.packages.*.is_child' => ['nullable', 'boolean'],
            'ecosystems.*.packages.*.depth' => ['nullable', 'integer', 'min:0', 'max:255'],
            'ecosystems.*.packages.*.source_url' => ['nullable', 'string', 'url', 'max:2048'],
            'ecosystems.*.packages.*.available_version' => ['nullable', 'string', 'max:255'],
            'ecosystems.*.packages.*.author' => ['nullable', 'string', 'max:255'],
            'ecosystems.*.findings' => ['present', 'array', 'max:'.Config::integer('inventory.payload_limits.findings')],
            'ecosystems.*.findings.*.package_name' => ['required', 'string', 'max:255'],
            'ecosystems.*.findings.*.package_version' => ['required', 'string', 'max:255'],
            'ecosystems.*.findings.*.advisory_id' => ['required', 'string', 'max:255'],
            'ecosystems.*.findings.*.severity' => ['required', Rule::enum(SeverityEnum::class)],
            'ecosystems.*.private_packages' => ['sometimes', 'array', 'max:'.Config::integer('inventory.payload_limits.private_packages')],
            'ecosystems.*.private_packages.*' => ['required', 'string', 'max:255'],
            'ecosystems.*.public_packages' => ['sometimes', 'array', 'max:'.Config::integer('inventory.payload_limits.public_packages')],
            'ecosystems.*.public_packages.*' => ['required', 'string', 'max:255'],
            'ecosystems.*.advisories' => ['sometimes', 'array', 'max:'.Config::integer('inventory.payload_limits.advisories')],
            'ecosystems.*.advisories.*.ecosystem' => ['required', 'string', Rule::in([
                EcosystemEnum::WpCore->value,
                EcosystemEnum::WpPlugin->value,
                EcosystemEnum::WpTheme->value,
            ])],
            'ecosystems.*.advisories.*.name' => ['required', 'string', 'max:255'],
            'ecosystems.*.advisories.*.payload' => ['present', 'array'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $ecosystems = $this->input('ecosystems', []);

            if (! is_array($ecosystems)) {
                return;
            }

            $validValues = array_map(fn (EcosystemEnum $e) => $e->value, EcosystemEnum::cases());

            foreach (array_keys($ecosystems) as $key) {
                if (! in_array($key, $validValues, strict: true)) {
                    $v->errors()->add("ecosystems.{$key}", "Unknown ecosystem: {$key}.");
                }
            }
        });
    }

    /**
     * @return array<string, array{packages: array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool|null, is_child: bool|null, depth: int|null, source_url: string|null, available_version: string|null, author: string|null}>, findings: array<int, array{package_name: string, package_version: string, advisory_id: string, severity: string}>, private_packages: list<string>, public_packages: list<string>, advisories: array<int, array{ecosystem: string, name: string, payload: array<int, mixed>}>}>
     */
    public function ecosystems(): array
    {
        /** @var array<string, array{packages: array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool|null, is_child: bool|null, depth: int|null, source_url: string|null, available_version: string|null, author: string|null}>, findings: array<int, array{package_name: string, package_version: string, advisory_id: string, severity: string}>, private_packages: list<string>, public_packages: list<string>, advisories: array<int, array{ecosystem: string, name: string, payload: array<int, mixed>}>}> */
        return $this->validated('ecosystems', []);
    }
}
