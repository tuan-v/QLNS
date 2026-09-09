<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    public function findActiveByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->where('status', 'active')
            ->first();
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function touchLastLogin(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
    }
}
