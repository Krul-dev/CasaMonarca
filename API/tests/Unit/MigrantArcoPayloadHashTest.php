<?php

namespace Tests\Unit;

use App\Services\Registry\MigrantArcoService;
use Tests\TestCase;

class MigrantArcoPayloadHashTest extends TestCase
{
    public function test_payload_hash_is_stable_across_nested_object_key_order(): void
    {
        $service = app(MigrantArcoService::class);
        $first = [
            'questionnaire' => [
                'definitionId' => 'migrant-intake-v2',
                'answers' => ['question-1' => ['value' => 'María', 'otherText' => 'Detalle']],
            ],
            'schemaVersion' => 2,
        ];
        $reordered = [
            'schemaVersion' => 2,
            'questionnaire' => [
                'answers' => ['question-1' => ['otherText' => 'Detalle', 'value' => 'María']],
                'definitionId' => 'migrant-intake-v2',
            ],
        ];

        $this->assertSame($service->payloadHash($first), $service->payloadHash($reordered));
    }

    public function test_payload_hash_preserves_list_order(): void
    {
        $service = app(MigrantArcoService::class);

        $this->assertNotSame(
            $service->payloadHash(['values' => ['Primero', 'Segundo']]),
            $service->payloadHash(['values' => ['Segundo', 'Primero']]),
        );
    }

    public function test_pending_questionnaire_payloads_accept_the_legacy_shallow_hash(): void
    {
        $service = app(MigrantArcoService::class);
        $original = [
            'schemaVersion' => 2,
            'questionnaire' => [
                'definitionId' => 'migrant-intake-v2',
                'answers' => ['question-1' => ['value' => 'José', 'otherText' => 'Nombre corregido']],
            ],
        ];
        $legacyPayload = $original;
        ksort($legacyPayload);
        $legacyHash = hash('sha256', json_encode($legacyPayload, JSON_THROW_ON_ERROR));
        $mysqlOrdered = [
            'questionnaire' => [
                'answers' => ['question-1' => ['otherText' => 'Nombre corregido', 'value' => 'José']],
                'definitionId' => 'migrant-intake-v2',
            ],
            'schemaVersion' => 2,
        ];

        $this->assertTrue($service->payloadMatchesHash($mysqlOrdered, $legacyHash));
    }
}
