<?php

declare(strict_types=1);

namespace Haryanvi\Services;

use Haryanvi\Core\ApiException;
use Haryanvi\Core\Logger;
use Haryanvi\Core\RateLimiter;
use Haryanvi\Repositories\TokenRepository;
use Haryanvi\Repositories\UserRepository;

/**
 * Registration, login, logout and account deletion.
 *
 * Both entry points are throttled per IP, responses are deliberately
 * indistinguishable for unknown accounts and wrong passwords, and the issued
 * bearer token is the only thing the client ever holds.
 */
final class AuthService
{
    private const AUTH_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly TokenRepository $tokens = new TokenRepository(),
    ) {
    }

    /**
     * @return array<string,mixed> payload for the API response
     */
    public function register(
        string $email,
        string $password,
        string $displayName,
        string $ip,
        string $device,
    ): array {
        if (!SettingsService::registrationOpen()) {
            throw ApiException::forbidden('New registrations are currently closed.');
        }

        $this->throttle('auth.register', $ip);

        if ($this->users->emailExists($email)) {
            // An explicit message is the right trade-off here: the signup form
            // needs to tell the user to log in instead, and an attacker can
            // learn the same thing from a password reset flow anyway.
            throw ApiException::conflict(
                'EMAIL_ALREADY_REGISTERED',
                'An account already exists for this email address. Try logging in instead.'
            );
        }

        $created = $this->users->create(
            $email,
            PasswordHasher::hash($password),
            $displayName,
            $ip
        );

        $token = $this->tokens->issue(
            $created['id'],
            $device,
            $ip,
            SettingsService::tokenTtlDays()
        );

        $this->users->touchLogin($created['id'], $ip);

        Logger::info('Account registered', ['user_id' => $created['id']]);

        return [
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
            'user'       => [
                'id'           => $created['uuid'],
                'email'        => $email,
                'display_name' => $displayName,
                'status'       => 'active',
            ],
        ];
    }

    /**
     * @return array<string,mixed> payload for the API response
     */
    public function login(string $email, string $password, string $ip, string $device): array
    {
        $this->throttle('auth.login', $ip);

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Spend the same time as a real verification would.
            PasswordHasher::dummyVerify();

            throw ApiException::unauthorized('Email address or password is incorrect.');
        }

        if (!PasswordHasher::verify($password, (string) $user['password_hash'])) {
            Logger::warning('Failed login', ['user_id' => (int) $user['id']]);

            throw ApiException::unauthorized('Email address or password is incorrect.');
        }

        if ((string) $user['status'] === 'blocked') {
            $reason = trim((string) ($user['blocked_reason'] ?? ''));

            throw ApiException::forbidden(
                $reason === ''
                    ? 'This account has been blocked. Contact support for help.'
                    : 'This account has been blocked: ' . $reason
            );
        }

        $userId = (int) $user['id'];

        // Opportunistically upgrade the stored hash if the policy moved on.
        if (PasswordHasher::needsRehash((string) $user['password_hash'])) {
            $this->users->updatePassword($userId, PasswordHasher::hash($password));
        }

        $token = $this->tokens->issue($userId, $device, $ip, SettingsService::tokenTtlDays());
        $this->users->touchLogin($userId, $ip);

        // A legitimate user who simply mistyped should not stay throttled.
        RateLimiter::clear('auth.login', $ip, self::AUTH_WINDOW_SECONDS);

        return [
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
            'user'       => [
                'id'           => (string) $user['uuid'],
                'email'        => (string) $user['email'],
                'display_name' => (string) $user['display_name'],
                'status'       => (string) $user['status'],
            ],
        ];
    }

    /**
     * Revoke the presented token. Optionally revoke every token for the
     * account, which is what "log out of all devices" needs.
     */
    public function logout(int $tokenId, int $userId, bool $allDevices): void
    {
        if ($allDevices) {
            $this->tokens->revokeAllForUser($userId);

            return;
        }

        $this->tokens->revoke($tokenId);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            throw ApiException::unauthorized();
        }

        if (!PasswordHasher::verify($currentPassword, (string) $user['password_hash'])) {
            throw ApiException::validation('Your current password is incorrect.', [
                'current_password' => 'Incorrect password.',
            ]);
        }

        $this->users->updatePassword($userId, PasswordHasher::hash($newPassword));

        // Every other device must re-authenticate after a password change.
        $this->tokens->revokeAllForUser($userId);

        Logger::info('Password changed', ['user_id' => $userId]);
    }

    /**
     * Permanent account deletion, required by the privacy commitments.
     */
    public function deleteAccount(int $userId, string $password): void
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            throw ApiException::unauthorized();
        }

        if (!PasswordHasher::verify($password, (string) $user['password_hash'])) {
            throw ApiException::validation('Password confirmation failed.', [
                'password' => 'Incorrect password.',
            ]);
        }

        $this->users->deleteAccount($userId);

        Logger::info('Account deleted', ['user_id' => $userId]);
    }

    private function throttle(string $scope, string $ip): void
    {
        RateLimiter::hit(
            $scope,
            $ip,
            SettingsService::getInt('auth_attempts_per_hour', 10),
            self::AUTH_WINDOW_SECONDS
        );
    }
}
