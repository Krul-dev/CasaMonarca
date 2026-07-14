<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class MigrantRegistryPayloadRequest extends FormRequest
{
    private const CIVIL_STATUSES = [
        'single',
        'married',
        'common_law_union',
        'separated',
        'divorced',
        'widowed',
    ];

    private const GENDERS = [
        'female',
        'male',
        'non_binary',
        'lgbtq_plus',
    ];

    private const POPULATION_GROUPS = [
        'adult',
        'older_adult',
        'accompanied_girl',
        'accompanied_boy',
        'accompanied_adolescent_boy',
        'accompanied_adolescent_girl',
        'unaccompanied_minor',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'payload_json' => ['required', 'array'],
            'payload_json.attentionDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payload_json.firstName' => ['required', 'string', 'max:120', "regex:/^[\pL\pM][\pL\pM .'-]*$/u"],
            'payload_json.firstLastName' => ['required', 'string', 'max:120', "regex:/^[\pL\pM][\pL\pM .'-]*$/u"],
            'payload_json.secondLastName' => ['nullable', 'string', 'max:120', "regex:/^[\pL\pM][\pL\pM .'-]*$/u"],
            'payload_json.fullName' => ['required', 'string', 'max:255'],
            'payload_json.phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9][0-9 ()-]{6,24}$/'],
            'payload_json.gender' => ['required', 'string', Rule::in(self::GENDERS)],
            'payload_json.countryOfOrigin' => ['required', 'string', 'max:120'],
            'payload_json.departmentState' => ['required', 'string', 'max:120'],
            'payload_json.civilStatus' => ['required', 'string', Rule::in(self::CIVIL_STATUSES)],
            'payload_json.birthDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:payload_json.attentionDate'],
            'payload_json.populationGroup' => ['required', 'string', Rule::in(self::POPULATION_GROUPS)],
            'payload_json.notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payload_json.firstName.regex' => 'The first name may contain only letters, spaces, apostrophes, periods, and hyphens.',
            'payload_json.firstLastName.regex' => 'The first last name may contain only letters, spaces, apostrophes, periods, and hyphens.',
            'payload_json.secondLastName.regex' => 'The second last name may contain only letters, spaces, apostrophes, periods, and hyphens.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $payload = $this->input('payload_json');

            if (! is_array($payload)) {
                return;
            }

            $this->validateFullName($validator, $payload);
            $this->validateAgeAndPopulationGroup($validator, $payload);
        });
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload_json');

        if (! is_array($payload)) {
            return;
        }

        foreach ([
            'attentionDate',
            'birthDate',
            'civilStatus',
            'countryOfOrigin',
            'departmentState',
            'firstLastName',
            'firstName',
            'fullName',
            'gender',
            'notes',
            'phone',
            'populationGroup',
            'secondLastName',
        ] as $field) {
            if (is_string($payload[$field] ?? null)) {
                $payload[$field] = trim((string) $payload[$field]);
            }
        }

        $this->merge(['payload_json' => $payload]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateFullName(Validator $validator, array $payload): void
    {
        $requiredNameParts = [
            $payload['firstName'] ?? null,
            $payload['firstLastName'] ?? null,
        ];

        if (collect($requiredNameParts)->contains(fn (mixed $part): bool => ! is_string($part) || trim($part) === '')) {
            return;
        }

        $nameParts = [
            ...$requiredNameParts,
            $payload['secondLastName'] ?? null,
        ];

        $expectedFullName = collect($nameParts)
            ->filter(fn (mixed $part): bool => is_string($part) && trim($part) !== '')
            ->map(fn (mixed $part): string => preg_replace('/\s+/u', ' ', trim((string) $part)) ?? '')
            ->implode(' ');
        $providedFullName = preg_replace('/\s+/u', ' ', trim((string) ($payload['fullName'] ?? ''))) ?? '';

        if ($providedFullName !== $expectedFullName) {
            $validator->errors()->add(
                'payload_json.fullName',
                'The full name must match the provided first name and surnames.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateAgeAndPopulationGroup(Validator $validator, array $payload): void
    {
        if (
            ! is_string($payload['birthDate'] ?? null) ||
            ! is_string($payload['attentionDate'] ?? null)
        ) {
            return;
        }

        try {
            $birthDate = CarbonImmutable::createFromFormat('!Y-m-d', $payload['birthDate']);
            $attentionDate = CarbonImmutable::createFromFormat('!Y-m-d', $payload['attentionDate']);
        } catch (\Throwable) {
            return;
        }

        if ($birthDate->isAfter($attentionDate)) {
            return;
        }

        $calculatedAge = $birthDate->diff($attentionDate)->y;
        $populationGroup = $payload['populationGroup'] ?? null;

        if (is_string($populationGroup) && ! $this->populationGroupMatchesAge($populationGroup, $calculatedAge)) {
            $validator->errors()->add(
                'payload_json.populationGroup',
                'The selected population group is not consistent with the calculated age.',
            );
        }
    }

    private function populationGroupMatchesAge(string $populationGroup, int $age): bool
    {
        return match ($populationGroup) {
            'adult' => $age >= 18 && $age <= 59,
            'older_adult' => $age >= 60,
            'accompanied_girl', 'accompanied_boy' => $age <= 11,
            'accompanied_adolescent_boy', 'accompanied_adolescent_girl' => $age >= 12 && $age <= 17,
            'unaccompanied_minor' => $age <= 17,
            default => false,
        };
    }
}
