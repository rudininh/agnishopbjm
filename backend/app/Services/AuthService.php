<?php

namespace App\Services;

use App\Repositories\UserRepository;
use Illuminate\Hashing\HashManager;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthService
{
    public function __construct(
        private UserRepository $users,
        private HashManager $hasher,
    ) {
    }

    public function register(array $data): array
    {
        $data['password'] = $this->hasher->make($data['password']);

        $user = $this->users->create($data);
        $token = $user->createToken('api-token')->plainTextToken;

        return [$user, $token];
    }

    public function login(array $data): array
    {
        $user = $this->users->findByEmail($data['email']);

        if (! $user || ! $this->hasher->check($data['password'], $user->password)) {
            throw new UnauthorizedHttpException('', 'Credentials tidak valid');
        }

        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return [$user, $token];
    }

    public function logout(): void
    {
        auth()->user()->currentAccessToken()?->delete();
    }
}
