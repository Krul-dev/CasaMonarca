<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustConfiguredProxies
{
    private const TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX;

    public function handle(Request $request, Closure $next): Response
    {
        $trustedProxies = $this->trustedProxies($request);

        if ($trustedProxies !== []) {
            $request::setTrustedProxies($trustedProxies, self::TRUSTED_HEADERS);
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function trustedProxies(Request $request): array
    {
        return array_values(array_filter(array_map(
            fn (string $proxy): ?string => match ($proxy) {
                '' => null,
                'REMOTE_ADDR' => $request->server->get('REMOTE_ADDR'),
                default => $proxy,
            },
            array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
        )));
    }
}
