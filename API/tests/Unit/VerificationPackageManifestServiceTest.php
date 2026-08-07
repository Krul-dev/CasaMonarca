<?php

namespace Tests\Unit;

use App\Services\Documents\VerificationPackageManifestService;
use Tests\TestCase;

class VerificationPackageManifestServiceTest extends TestCase
{
    public function test_canonical_json_matches_the_serialized_form_of_collections(): void
    {
        $service = app(VerificationPackageManifestService::class);
        $withCollection = [
            'signatures' => collect([
                ['signedBy' => ['name' => 'Admin'], 'id' => 20],
            ]),
        ];
        $serialized = json_decode(
            json_encode($withCollection, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            $service->canonicalJson($serialized),
            $service->canonicalJson($withCollection),
        );
    }
}
