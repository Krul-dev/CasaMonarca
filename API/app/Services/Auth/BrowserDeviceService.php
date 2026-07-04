<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class BrowserDeviceService
{
    public const COOKIE_NAME = 'cm_device_id';

    private const COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * @return array{
     *     device: UserDevice,
     *     isNewDevice: bool,
     *     metadata: array<string, mixed>
     * }
     */
    public function rememberAuthenticatedDevice(Request $request, User $user): array
    {
        $rawDeviceId = $this->resolveOrCreateRawDeviceId($request);
        $deviceHash = $this->hashDeviceId($rawDeviceId);

        /** @var UserDevice|null $device */
        $device = $user->browserDevices()
            ->where('device_identifier_hash', $deviceHash)
            ->first();

        $isNewDevice = ! $device instanceof UserDevice;
        $now = now();

        if (! $device instanceof UserDevice) {
            $device = new UserDevice([
                'device_identifier_hash' => $deviceHash,
                'first_seen_at' => $now,
            ]);
            $device->user()->associate($user);
        }

        $device->forceFill([
            'alias' => $this->aliasFromUserAgent($request->userAgent()),
            'user_agent' => $request->userAgent(),
            'last_ip_address' => $request->ip(),
            'last_seen_at' => $now,
            'last_login_at' => $now,
        ])->save();

        $this->queueDeviceCookie($request, $rawDeviceId);

        return [
            'device' => $device,
            'isNewDevice' => $isNewDevice,
            'metadata' => $this->auditMetadata($device, $isNewDevice),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(UserDevice $device, bool $isNewDevice): array
    {
        return [
            'deviceAlias' => $device->alias,
            'deviceId' => $device->identifierPreview(),
            'newDevice' => $isNewDevice,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function existingDeviceCookieMetadata(Request $request): array
    {
        $rawDeviceId = $this->resolveExistingRawDeviceId($request);

        if ($rawDeviceId === null) {
            return [];
        }

        return [
            'deviceCookieId' => substr($this->hashDeviceId($rawDeviceId), 0, 16),
        ];
    }

    private function resolveOrCreateRawDeviceId(Request $request): string
    {
        $existingRawDeviceId = $this->resolveExistingRawDeviceId($request);

        if ($existingRawDeviceId !== null) {
            return $existingRawDeviceId;
        }

        return Str::random(64);
    }

    private function resolveExistingRawDeviceId(Request $request): ?string
    {
        $cookieValue = $request->cookies->get(self::COOKIE_NAME);

        if (is_string($cookieValue) && preg_match('/^[A-Za-z0-9]{48,96}$/', $cookieValue) === 1) {
            return $cookieValue;
        }

        return null;
    }

    private function hashDeviceId(string $rawDeviceId): string
    {
        return hash_hmac('sha256', $rawDeviceId, (string) config('app.key'));
    }

    private function queueDeviceCookie(Request $request, string $rawDeviceId): void
    {
        Cookie::queue(cookie(
            name: self::COOKIE_NAME,
            value: $rawDeviceId,
            minutes: self::COOKIE_MINUTES,
            path: '/',
            domain: null,
            secure: (bool) (config('session.secure') ?? $request->isSecure()),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }

    private function aliasFromUserAgent(?string $userAgent): string
    {
        $normalized = trim((string) $userAgent);

        if ($normalized === '') {
            return 'Unknown browser';
        }

        $browser = match (true) {
            str_contains($normalized, 'Edg/') => 'Edge',
            str_contains($normalized, 'Firefox/') => 'Firefox',
            str_contains($normalized, 'Chromium/') => 'Chromium',
            str_contains($normalized, 'Chrome/') => 'Chrome',
            str_contains($normalized, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($normalized, 'Android') => 'Android',
            str_contains($normalized, 'iPhone') || str_contains($normalized, 'iPad') => 'iOS',
            str_contains($normalized, 'Windows') => 'Windows',
            str_contains($normalized, 'Mac OS X') || str_contains($normalized, 'Macintosh') => 'macOS',
            str_contains($normalized, 'Linux') || str_contains($normalized, 'X11') => 'Linux',
            default => 'unknown device',
        };

        return "{$browser} on {$platform}";
    }
}
