<?php

namespace Tests\Unit;

use App\Http\Middleware\RequireFeatureEnabled;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class RequireFeatureEnabledTest extends TestCase
{
    public function test_disabled_feature_returns_not_found(): void
    {
        config()->set('features.arco', false);

        $this->expectException(NotFoundHttpException::class);

        app(RequireFeatureEnabled::class)->handle(
            Request::create('/registry/migrants/arco'),
            fn () => response('enabled'),
            'arco',
        );
    }

    public function test_enabled_feature_allows_request(): void
    {
        config()->set('features.arco', true);

        $response = app(RequireFeatureEnabled::class)->handle(
            Request::create('/registry/migrants/arco'),
            fn () => response('enabled'),
            'arco',
        );

        $this->assertSame('enabled', $response->getContent());
    }
}
