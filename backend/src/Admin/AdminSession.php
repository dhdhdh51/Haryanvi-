<?php

declare(strict_types=1);

namespace Haryanvi\Admin;

use Haryanvi\Core\Config;
use Haryanvi\Core\Logger;
use Haryanvi\Services\PasswordHasher;

/**
 * Admin dashboard session, CSRF protection and authorisation.
 *
 * Separate from the API's bearer-token auth on purpose: the dashboard is a
 * browser surface and needs cookie sessions plus CSRF defence, while the
 * Android client needs neither.
 */
final class AdminSession
{
    private const IDLE_TIMEOUT    = 3600;  // 1 hour of inactivity
    private const ABSOLUTE_MAX    = 43200; // 12 hours total
    private const CSRF_TOKEN_KEY  = '_csrf';

    private ?array $admin = null;

    public function __construct(private readonly AdminRepository $admins = new AdminRepository())
    {
        $this->start();
        $this->loadAdmin();
    }

    /* ----------------------------------------------------------------
     | Session lifecycle
     * -------------------------------------------------------------- */

    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = ($_SERVER['HTTPS'] ?? '') !== ''
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        session_name(Config::getString('security.session_name', 'hy_admin_sess'));

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            // Strict is viable because the dashboard has no cross-site flows.
            'samesite' => 'Strict',
        ]);

        session_start();

        // Expire idle and over-long sessions server-side.
        $now         = time();
        $lastSeen    = (int) ($_SESSION['_last_seen'] ?? $now);
        $establishedAt = (int) ($_SESSION['_started'] ?? $now);

        if (isset($_SESSION['admin_id'])) {
            if ($now - $lastSeen > self::IDLE_TIMEOUT || $now - $establishedAt > self::ABSOLUTE_MAX) {
                $this->destroy();
                session_start();
                $_SESSION['_flash'] = ['warning', 'Your session expired. Please sign in again.'];
            }
        }

        $_SESSION['_last_seen'] = $now;
        $_SESSION['_started'] ??= $now;
    }

    private function loadAdmin(): void
    {
        $adminId = $_SESSION['admin_id'] ?? null;

        if (!is_int($adminId) && !is_numeric($adminId)) {
            return;
        }

        $admin = $this->admins->find((int) $adminId);

        // A deactivated or deleted admin loses access immediately.
        if ($admin === null || (int) $admin['is_active'] !== 1) {
            $this->destroy();

            return;
        }

        $this->admin = $admin;
    }

    /* ----------------------------------------------------------------
     | Authentication
     * -------------------------------------------------------------- */

    /**
     * @return array{ok:bool,message:string}
     */
    public function attemptLogin(string $username, string $password, string $ip, string $userAgent): array
    {
        $admin = $this->admins->findByUsername($username);

        if ($admin === null) {
            // Same timing and message as a wrong password.
            PasswordHasher::dummyVerify();

            return ['ok' => false, 'message' => 'Incorrect username or password.'];
        }

        if ((int) $admin['is_active'] !== 1) {
            return ['ok' => false, 'message' => 'This administrator account is disabled.'];
        }

        if ($this->admins->isLocked($admin)) {
            $minutes = (int) ceil($this->admins->lockRemainingSeconds($admin) / 60);

            return [
                'ok'      => false,
                'message' => sprintf(
                    'Too many failed attempts. This account is locked for another %d minute%s.',
                    $minutes,
                    $minutes === 1 ? '' : 's'
                ),
            ];
        }

        if (!PasswordHasher::verify($password, (string) $admin['password_hash'])) {
            $this->admins->registerFailedAttempt((int) $admin['id']);
            $this->admins->log(
                (int) $admin['id'],
                'admin.login_failed',
                'admin_user',
                (string) $admin['id'],
                [],
                $ip,
                $userAgent
            );

            Logger::warning('Admin login failed', ['admin_id' => (int) $admin['id']]);

            return ['ok' => false, 'message' => 'Incorrect username or password.'];
        }

        // Fresh session id defeats fixation.
        session_regenerate_id(true);

        $_SESSION['admin_id']   = (int) $admin['id'];
        $_SESSION['_started']   = time();
        $_SESSION['_last_seen'] = time();
        unset($_SESSION[self::CSRF_TOKEN_KEY]);

        $this->admins->registerSuccessfulLogin((int) $admin['id'], $ip);
        $this->admins->log((int) $admin['id'], 'admin.login', 'admin_user', (string) $admin['id'], [], $ip, $userAgent);

        $this->admin = $this->admins->find((int) $admin['id']);

        return ['ok' => true, 'message' => 'Signed in.'];
    }

    public function logout(string $ip = '', string $userAgent = ''): void
    {
        if ($this->admin !== null) {
            $this->admins->log(
                (int) $this->admin['id'],
                'admin.logout',
                'admin_user',
                (string) $this->admin['id'],
                [],
                $ip,
                $userAgent
            );
        }

        $this->destroy();
    }

    private function destroy(): void
    {
        $this->admin = null;
        $_SESSION    = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name() ?: 'hy_admin_sess',
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Strict',
                ]
            );
        }

        session_destroy();
    }

    /* ----------------------------------------------------------------
     | State
     * -------------------------------------------------------------- */

    public function isAuthenticated(): bool
    {
        return $this->admin !== null;
    }

    /** @return array<string,mixed> */
    public function admin(): array
    {
        if ($this->admin === null) {
            throw new \RuntimeException('No administrator is signed in.');
        }

        return $this->admin;
    }

    public function id(): int
    {
        return (int) $this->admin()['id'];
    }

    public function username(): string
    {
        return (string) $this->admin()['username'];
    }

    public function role(): string
    {
        return (string) $this->admin()['role'];
    }

    public function mustChangePassword(): bool
    {
        return $this->admin !== null && (int) $this->admin['must_change_password'] === 1;
    }

    /**
     * Moderators can review translation quality but cannot change
     * configuration, credentials or accounts.
     */
    public function canWrite(): bool
    {
        return in_array($this->role(), ['super_admin', 'admin'], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role() === 'super_admin';
    }

    public function canReviewFeedback(): bool
    {
        return $this->isAuthenticated();
    }

    /* ----------------------------------------------------------------
     | CSRF
     * -------------------------------------------------------------- */

    public function csrfToken(): string
    {
        if (!isset($_SESSION[self::CSRF_TOKEN_KEY]) || !is_string($_SESSION[self::CSRF_TOKEN_KEY])) {
            $_SESSION[self::CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_TOKEN_KEY];
    }

    public function verifyCsrf(?string $submitted): bool
    {
        $expected = $_SESSION[self::CSRF_TOKEN_KEY] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /* ----------------------------------------------------------------
     | Flash messages
     * -------------------------------------------------------------- */

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'] = [$type, $message];
    }

    /** @return array{0:string,1:string}|null */
    public function takeFlash(): ?array
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        if (!is_array($flash) || count($flash) !== 2) {
            return null;
        }

        return [(string) $flash[0], (string) $flash[1]];
    }

    /** Record an administrative action in the audit trail. */
    public function audit(string $action, ?string $entityType = null, ?string $entityId = null, array $details = []): void
    {
        $this->admins->log(
            $this->admin === null ? null : (int) $this->admin['id'],
            $action,
            $entityType,
            $entityId,
            $details,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
    }
}
