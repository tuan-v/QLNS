<?php

namespace App\Auth;

use App\Services\Jwt\JwtService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class JwtGuard implements Guard
{
    private ?Authenticatable $user = null;

    private bool $resolved = false;

    public function __construct(
        private readonly UserProvider $provider,
        private readonly Request $request,
        private readonly JwtService $jwtService,
    ) {
    }

    public function check(): bool
    {
        return ! is_null($this->user());
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $token = $this->bearerToken();

        if (! $token) {
            return $this->user = null;
        }

        $payload = $this->jwtService->decodeAccessToken($token);

        if (! $payload || ! isset($payload->sub)) {
            return $this->user = null;
        }

        $user = $this->provider->retrieveById($payload->sub);

        if (! $user || $user->status !== 'active') {
            return $this->user = null;
        }

        return $this->user = $user;

    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return ! is_null($this->user);
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
        $this->resolved = true;
    }

    private function bearerToken(): ?string
    {
        $header = $this->request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }
}
