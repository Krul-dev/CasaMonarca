<?php

namespace App\Services\Registry;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class MigrantQuestionnaireDefinitionService
{
    public const DEFINITION_ID = 'migrant-intake-v2';

    /** @var array<string, mixed>|null */
    private ?array $definition = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        if ($this->definition !== null) {
            return $this->definition;
        }

        $path = resource_path('registry-questionnaires/'.self::DEFINITION_ID.'.json');
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new \RuntimeException('The migrant questionnaire definition is invalid.');
        }

        return $this->definition = $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload, bool $complete): array
    {
        $questionnaire = $payload['questionnaire'] ?? null;
        $answers = is_array($questionnaire) && is_array($questionnaire['answers'] ?? null)
            ? $questionnaire['answers']
            : [];
        $errors = [];

        if (($payload['schemaVersion'] ?? null) !== 2) {
            $errors['payload_json.schemaVersion'][] = 'The questionnaire schema version must be 2.';
        }

        if (($questionnaire['definitionId'] ?? null) !== self::DEFINITION_ID) {
            $errors['payload_json.questionnaire.definitionId'][] = 'The questionnaire definition is not supported.';
        }

        $questions = collect($this->definition()['questions'] ?? [])->keyBy('id');
        $normalizedAnswers = [];

        foreach ($answers as $questionId => $answer) {
            $question = $questions->get((string) $questionId);

            if (! is_array($question)) {
                $errors["payload_json.questionnaire.answers.{$questionId}"][] = 'This question does not exist in the active questionnaire.';

                continue;
            }

            $normalized = $this->normalizeAnswer($question, $answer, $errors);

            if ($normalized !== null) {
                $normalizedAnswers[(string) $questionId] = $normalized;
            }
        }

        $reachable = $this->reachableQuestionIds($normalizedAnswers);

        if ($complete) {
            foreach ($reachable as $questionId) {
                $question = $questions->get($questionId);

                if (! is_array($question) || ! ($question['required'] ?? false)) {
                    continue;
                }

                $answer = $normalizedAnswers[$questionId] ?? null;

                if ($answer === null || $this->answerIsEmpty($answer)) {
                    $errors["payload_json.questionnaire.answers.{$questionId}"][] = 'Esta respuesta es obligatoria.';

                    continue;
                }

                if ($this->usesCustomChoice($question, $answer) && trim((string) ($answer['otherText'] ?? '')) === '') {
                    $errors["payload_json.questionnaire.answers.{$questionId}.otherText"][] = 'Especifique la respuesta en español.';
                }
            }
        }

        $normalizedAnswers = array_intersect_key($normalizedAnswers, array_flip($reachable));
        $this->validateDemographicIntegrity($normalizedAnswers, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'schemaVersion' => 2,
            'questionnaire' => [
                'definitionId' => self::DEFINITION_ID,
                'answers' => $normalizedAnswers,
            ],
            ...$this->summary($normalizedAnswers),
        ];
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    public function upgradeLegacyPayload(array $legacy): array
    {
        $mapping = $this->definition()['summaryMappings'] ?? [];
        $answers = [];
        $choiceMappings = [
            'gender' => ['female' => 'Femenino', 'woman' => 'Femenino', 'male' => 'Masculino', 'man' => 'Masculino', 'non_binary' => 'No binario', 'lgbtq_plus' => 'LGBTIQ+'],
            'countryOfOrigin' => ['Venezuela' => 'Venezuela (República Bolivariana de)'],
            'civilStatus' => ['single' => 'Soltera / Soltero', 'married' => 'Casado / Casado', 'common_law_union' => 'Unión Libre', 'union' => 'Unión Libre', 'separated' => 'Separada / Separado', 'divorced' => 'Divorciada / Divorciado', 'widowed' => 'Viuda / Viudo', 'other' => 'Otro'],
            'populationGroup' => ['adult' => 'Adulto (18-59 años)', 'older_adult' => 'Adulto mayor (+60 años)', 'accompanied_girl' => 'Niña acompañada', 'accompanied_boy' => 'Niño acompañado', 'accompanied_adolescent_boy' => 'Adolescente hombre acompañado', 'accompanied_adolescent_girl' => 'Adolescente mujer acompañada', 'unaccompanied_minor' => 'NNA No acompañado'],
        ];

        foreach (['attentionDate', 'firstName', 'firstLastName', 'secondLastName', 'phone', 'gender', 'countryOfOrigin', 'departmentState', 'civilStatus', 'birthDate', 'populationGroup', 'notes'] as $field) {
            $questionId = $mapping[$field] ?? null;
            $value = $legacy[$field] ?? null;

            if (! is_string($questionId) || ! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $canonical = $choiceMappings[$field][(string) $value] ?? trim((string) $value);
            $answers[$questionId] = ['value' => $canonical];
        }

        $attentionDate = $legacy['attentionDate'] ?? null;
        $birthDate = $legacy['birthDate'] ?? null;
        $ageQuestionId = $mapping['age'] ?? null;

        if (is_string($attentionDate) && is_string($birthDate) && is_string($ageQuestionId)) {
            $age = $this->calculatedAgeChoice($birthDate, $attentionDate);

            if ($age !== null) {
                $answers[$ageQuestionId] = ['value' => $age];
            }
        }

        return [
            'schemaVersion' => 2,
            'questionnaire' => ['definitionId' => self::DEFINITION_ID, 'answers' => $answers],
            ...$this->summary($answers),
        ];
    }

    /** @param array<string, mixed> $answers @return list<string> */
    public function reachableQuestionIds(array $answers): array
    {
        $questions = collect($this->definition()['questions'] ?? [])->keyBy('id');
        $current = data_get($this->definition(), 'questions.0.id');
        $reachable = [];
        $visited = [];

        while (is_string($current) && ! isset($visited[$current])) {
            $visited[$current] = true;
            $reachable[] = $current;
            $question = $questions->get($current);

            if (! is_array($question)) {
                break;
            }

            $next = $question['defaultNext'] ?? ['kind' => 'end'];
            $answer = $answers[$current] ?? null;

            if (($question['type'] ?? null) === 'choice' && is_array($answer) && ! ($question['multipleSelection'] ?? false)) {
                $selected = $answer['value'] ?? null;
                $choice = collect($question['choices'] ?? [])->first(
                    fn (mixed $candidate): bool => is_array($candidate) && ($candidate['value'] ?? null) === $selected,
                );

                if (is_array($choice)) {
                    $next = $choice['next'] ?? $next;
                }
            }

            $current = ($next['kind'] ?? null) === 'question' && is_string($next['questionId'] ?? null)
                ? $next['questionId']
                : null;
        }

        return $reachable;
    }

    /** @param array<string, mixed> $payload @return list<array{title: string, answers: list<array{question: string, answer: string}>}> */
    public function spanishAnswerSections(array $payload): array
    {
        if (($payload['schemaVersion'] ?? null) !== 2 || ! is_array(data_get($payload, 'questionnaire.answers'))) {
            return [];
        }

        $answers = data_get($payload, 'questionnaire.answers', []);
        $reachable = array_flip($this->reachableQuestionIds($answers));
        $questions = collect($this->definition()['questions'] ?? [])->keyBy('id');

        return collect($this->definition()['sections'] ?? [])->map(function (mixed $section) use ($answers, $questions, $reachable): ?array {
            if (! is_array($section)) {
                return null;
            }

            $rows = $questions
                ->filter(fn (mixed $question): bool => is_array($question)
                    && ($question['sectionId'] ?? null) === ($section['id'] ?? null)
                    && isset($reachable[$question['id'] ?? ''])
                    && isset($answers[$question['id'] ?? '']))
                ->map(function (array $question) use ($answers): array {
                    $answer = $answers[$question['id']];
                    $value = $answer['value'] ?? '';
                    $parts = is_array($value) ? $value : [$value];
                    $rendered = collect($parts)->map(function (mixed $part) use ($answer): string {
                        if ($part === 'Otro' && trim((string) ($answer['otherText'] ?? '')) !== '') {
                            return 'Otro: '.trim((string) $answer['otherText']);
                        }

                        return (string) $part;
                    })->implode(', ');

                    return [
                        'question' => (string) data_get($question, 'title.es', ''),
                        'answer' => $rendered,
                    ];
                })->values()->all();

            return $rows === [] ? null : [
                'title' => (string) data_get($section, 'title.es', ''),
                'answers' => $rows,
            ];
        })->filter()->values()->all();
    }

    /** @param array<string, mixed> $question @param array<string, list<string>> $errors @return array<string, mixed>|null */
    private function normalizeAnswer(array $question, mixed $answer, array &$errors): ?array
    {
        $questionId = (string) $question['id'];
        $path = "payload_json.questionnaire.answers.{$questionId}";

        if (! is_array($answer) || ! array_key_exists('value', $answer)) {
            $errors[$path][] = 'The answer must contain a value.';

            return null;
        }

        $value = $answer['value'];
        $type = $question['type'] ?? null;

        if ($type === 'choice') {
            $allowed = collect($question['choices'] ?? [])->pluck('value')->filter()->values()->all();

            if ($question['multipleSelection'] ?? false) {
                if (! is_array($value)) {
                    $errors["{$path}.value"][] = 'Select one or more valid options.';

                    return null;
                }

                $value = array_values(array_unique(array_map(fn (mixed $item): string => trim((string) $item), $value)));

                if (array_diff($value, $allowed) !== []) {
                    $errors["{$path}.value"][] = 'One or more selected options are invalid.';
                }
            } else {
                $value = trim((string) $value);

                if ($value !== '' && ! in_array($value, $allowed, true)) {
                    $errors["{$path}.value"][] = 'The selected option is invalid.';
                }
            }
        } else {
            if (! is_scalar($value) && $value !== null) {
                $errors["{$path}.value"][] = 'The answer must be text.';

                return null;
            }

            $value = trim((string) $value);

            if (mb_strlen($value) > 5000) {
                $errors["{$path}.value"][] = 'The answer may not exceed 5000 characters.';
            }

            if ($type === 'date' && $value !== '' && ! $this->isDate($value)) {
                $errors["{$path}.value"][] = 'Use a valid date in YYYY-MM-DD format.';
            }

            if (($question['numeric'] ?? false) && $value !== '' && ! is_numeric($value)) {
                $errors["{$path}.value"][] = 'The answer must be numeric.';
            }
        }

        $normalized = ['value' => $value];
        $otherText = trim((string) ($answer['otherText'] ?? ''));

        if ($otherText !== '') {
            $normalized['otherText'] = mb_substr($otherText, 0, 5000);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $answers @return array<string, mixed> */
    private function summary(array $answers): array
    {
        $mapping = $this->definition()['summaryMappings'] ?? [];
        $value = fn (string $field): string => trim((string) data_get($answers, ($mapping[$field] ?? '__missing').'.value', ''));
        $firstName = $value('firstName');
        $firstLastName = $value('firstLastName');
        $secondLastName = $value('secondLastName');

        return [
            'attentionDate' => $value('attentionDate'),
            'firstName' => $firstName,
            'firstLastName' => $firstLastName,
            'secondLastName' => $secondLastName,
            'fullName' => collect([$firstName, $firstLastName, $secondLastName])->filter()->implode(' '),
            'phone' => $value('phone'),
            'gender' => ['Femenino' => 'female', 'Masculino' => 'male', 'No binario' => 'non_binary', 'LGBTIQ+' => 'lgbtq_plus'][$value('gender')] ?? '',
            'countryOfOrigin' => $value('countryOfOrigin'),
            'departmentState' => $value('departmentState'),
            'civilStatus' => ['Casado / Casado' => 'married', 'Divorciada / Divorciado' => 'divorced', 'Soltera / Soltero' => 'single', 'Separada / Separado' => 'separated', 'Viuda / Viudo' => 'widowed', 'Unión Libre' => 'common_law_union', 'Otro' => 'other'][$value('civilStatus')] ?? '',
            'birthDate' => $value('birthDate'),
            'age' => $value('age'),
            'populationGroup' => ['Adulto (18-59 años)' => 'adult', 'Adulto mayor (+60 años)' => 'older_adult', 'Niña acompañada' => 'accompanied_girl', 'Niño acompañado' => 'accompanied_boy', 'Adolescente hombre acompañado' => 'accompanied_adolescent_boy', 'Adolescente mujer acompañada' => 'accompanied_adolescent_girl', 'NNA No acompañado' => 'unaccompanied_minor'][$value('populationGroup')] ?? '',
            'notes' => $value('notes'),
        ];
    }

    /** @param array<string, mixed> $answers @param array<string, list<string>> $errors */
    private function validateDemographicIntegrity(array $answers, array &$errors): void
    {
        $mapping = $this->definition()['summaryMappings'] ?? [];
        $attention = data_get($answers, ($mapping['attentionDate'] ?? '').'.value');
        $birth = data_get($answers, ($mapping['birthDate'] ?? '').'.value');
        $age = data_get($answers, ($mapping['age'] ?? '').'.value');
        $group = data_get($answers, ($mapping['populationGroup'] ?? '').'.value');

        if (! is_string($attention) || ! is_string($birth) || $attention === '' || $birth === '' || ! $this->isDate($attention) || ! $this->isDate($birth)) {
            return;
        }

        $expectedAge = $this->calculatedAgeChoice($birth, $attention);

        if ($expectedAge === null) {
            $errors['payload_json.questionnaire.answers.'.($mapping['birthDate'] ?? '')][] = 'La fecha de nacimiento debe ser anterior o igual a la fecha de atención.';

            return;
        }

        if (is_string($age) && $age !== '' && $age !== $expectedAge) {
            $errors['payload_json.questionnaire.answers.'.($mapping['age'] ?? '')][] = 'La edad no coincide con las fechas registradas.';
        }

        $years = $expectedAge === '0 - 11 meses' ? 0 : (int) $expectedAge;
        $validGroup = match ($group) {
            'Adulto (18-59 años)' => $years >= 18 && $years <= 59,
            'Adulto mayor (+60 años)' => $years >= 60,
            'Niña acompañada', 'Niño acompañado' => $years <= 11,
            'Adolescente hombre acompañado', 'Adolescente mujer acompañada' => $years >= 12 && $years <= 17,
            'NNA No acompañado' => $years <= 17,
            default => true,
        };

        if (! $validGroup) {
            $errors['payload_json.questionnaire.answers.'.($mapping['populationGroup'] ?? '')][] = 'El grupo de población no coincide con la edad registrada.';
        }
    }

    private function calculatedAgeChoice(string $birthDate, string $attentionDate): ?string
    {
        try {
            $birth = CarbonImmutable::createFromFormat('!Y-m-d', $birthDate);
            $attention = CarbonImmutable::createFromFormat('!Y-m-d', $attentionDate);
        } catch (\Throwable) {
            return null;
        }

        if ($birth->isAfter($attention)) {
            return null;
        }

        $years = $birth->diff($attention)->y;

        return $years === 0 ? '0 - 11 meses' : (string) min(90, $years);
    }

    /** @param array<string, mixed> $answer */
    private function answerIsEmpty(array $answer): bool
    {
        $value = $answer['value'] ?? null;

        return is_array($value) ? $value === [] : trim((string) $value) === '';
    }

    /** @param array<string, mixed> $question @param array<string, mixed> $answer */
    private function usesCustomChoice(array $question, array $answer): bool
    {
        $value = $answer['value'] ?? null;
        $selected = is_array($value) ? $value : [$value];

        return collect($question['choices'] ?? [])->contains(
            fn (mixed $choice): bool => is_array($choice) && ($choice['custom'] ?? false) && in_array($choice['value'] ?? null, $selected, true),
        );
    }

    private function isDate(string $value): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }
}
