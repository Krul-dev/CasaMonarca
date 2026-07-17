<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Registry\MigrantRegistryBulkApprovalVerifyController;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Registry\MigrantRegistryService;
use App\Services\Security\SecurityChallengeIntentService;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

class MigrantRegistryBulkApprovalVerifyControllerTest extends TestCase
{
    public function test_bulk_approval_target_hash_ignores_json_object_key_order(): void
    {
        $sessionTargets = [[
            'id' => 6,
            'status' => 'pending_approval',
            'pendingAction' => 'create',
            'payloadHash' => str_repeat('a', 64),
        ]];
        $databaseTargets = [[
            'id' => 6,
            'status' => 'pending_approval',
            'payloadHash' => str_repeat('a', 64),
            'pendingAction' => 'create',
        ]];

        $this->assertSame(
            MigrantRegistryService::approvalTargetsHash($sessionTargets),
            MigrantRegistryService::approvalTargetsHash($databaseTargets),
        );
    }

    public function test_missing_bulk_approval_challenge_is_not_reported_as_unauthenticated(): void
    {
        $request = Request::create('/registry/migrants/bulk-approval/verify', 'POST');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        $controller = new MigrantRegistryBulkApprovalVerifyController(
            $this->createMock(MigrantRegistryService::class),
            $this->createMock(SecurityChallengeIntentService::class),
            $this->createMock(WebauthnAssertionService::class),
        );

        $response = $controller($request);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            'Bulk migrant approval challenge was not initiated.',
            $response->getData(true)['message'],
        );
    }
}
