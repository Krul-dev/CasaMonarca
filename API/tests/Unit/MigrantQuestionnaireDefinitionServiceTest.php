<?php

namespace Tests\Unit;

use App\Services\Registry\MigrantQuestionnaireDefinitionService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MigrantQuestionnaireDefinitionServiceTest extends TestCase
{
    public function test_definition_contains_the_complete_translated_branch_graph(): void
    {
        $definition = $this->service()->definition();
        $questions = collect($definition['questions']);
        $ids = $questions->pluck('id');
        $explicitBranches = $questions->sum(fn (array $question): int => ($question['explicitQuestionBranch'] ? 1 : 0)
            + collect($question['choices'])->where('explicitBranch', true)->count());

        $this->assertCount(73, $questions);
        $this->assertCount(73, $ids->unique());
        $this->assertSame(77, $explicitBranches);
        $this->assertCount(8, $definition['sections']);
        $this->assertSame(['es', 'en', 'fr', 'ht'], collect($definition['locales'])->pluck('id')->all());

        foreach ($questions as $question) {
            $this->assertSame(['en', 'es', 'fr', 'ht'], collect($question['title'])->keys()->sort()->values()->all());
            foreach ($question['choices'] as $choice) {
                $this->assertSame(['en', 'es', 'fr', 'ht'], collect($choice['label'])->keys()->sort()->values()->all());
                if (($choice['next']['kind'] ?? null) === 'question') {
                    $this->assertContains($choice['next']['questionId'], $ids);
                }
            }
        }
    }

    public function test_partial_payload_keeps_spanish_canonical_answers_and_prunes_hidden_branches(): void
    {
        $service = $this->service();
        $definition = $service->definition();
        $question = collect($definition['questions'])->firstWhere('number', 23);
        $hiddenQuestion = collect($definition['questions'])->firstWhere('number', 24);
        $nextQuestion = collect($definition['questions'])->firstWhere('number', 26);
        $answers = $this->answersThroughQuestion($definition, 23);
        $answers[$question['id']] = ['value' => 'Pasaporte'];
        $answers[$hiddenQuestion['id']] = ['value' => 'Condición de refugiado'];
        $answers[$nextQuestion['id']] = ['value' => 'Sí'];

        $payload = $service->normalizePayload([
            'schemaVersion' => 2,
            'questionnaire' => ['definitionId' => $definition['id'], 'answers' => $answers],
        ], false);

        $this->assertSame('Pasaporte', data_get($payload, "questionnaire.answers.{$question['id']}.value"));
        $this->assertArrayNotHasKey($hiddenQuestion['id'], $payload['questionnaire']['answers']);
        $this->assertArrayHasKey($nextQuestion['id'], $payload['questionnaire']['answers']);
    }

    public function test_complete_payload_requires_reachable_questions(): void
    {
        $definition = $this->service()->definition();

        try {
            $this->service()->normalizePayload([
                'schemaVersion' => 2,
                'questionnaire' => ['definitionId' => $definition['id'], 'answers' => []],
            ], true);
            $this->fail('An empty questionnaire must not be submitted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_legacy_country_names_are_upgraded_to_the_questionnaire_canonical_value(): void
    {
        $service = $this->service();
        $definition = $service->definition();
        $countryQuestionId = $definition['summaryMappings']['countryOfOrigin'];

        $payload = $service->upgradeLegacyPayload(['countryOfOrigin' => 'Venezuela']);

        $this->assertSame(
            'Venezuela (República Bolivariana de)',
            data_get($payload, "questionnaire.answers.{$countryQuestionId}.value"),
        );
    }

    private function service(): MigrantQuestionnaireDefinitionService
    {
        return app(MigrantQuestionnaireDefinitionService::class);
    }

    /** @param array<string, mixed> $definition @return array<string, array{value: string|list<string>}> */
    private function answersThroughQuestion(array $definition, int $number): array
    {
        $answers = [];

        foreach (collect($definition['questions'])->where('number', '<=', $number) as $question) {
            if (! $question['required']) {
                continue;
            }

            $answers[$question['id']] = ['value' => match ($question['type']) {
                'choice' => $question['multipleSelection'] ? [$question['choices'][0]['value']] : $question['choices'][0]['value'],
                'date' => $question['number'] === 10 ? '1990-01-01' : '',
                default => $question['numeric'] ? '1' : 'Respuesta en español',
            }];
        }

        return $answers;
    }
}
