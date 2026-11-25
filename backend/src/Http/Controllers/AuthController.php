<?php

declare(strict_types=1);

namespace Haryanvi\Http\Controllers;

use Haryanvi\Core\Request;
use Haryanvi\Core\Response;
use Haryanvi\Core\RouteContext;
use Haryanvi\Core\Validator;
use Haryanvi\Services\AuthService;

/**
 * POST /api/v1/auth/register
 * POST /api/v1/auth/login
 * POST /api/v1/auth/logout
 * POST /api/v1/auth/password
 * POST /api/v1/auth/delete-account
 */
final class AuthController
{
    public function __construct(private readonly AuthService $auth = new AuthService())
    {
    }

    public function register(Request $request, RouteContext $context): void
    {
        $v     = new Validator($request->body());
        $email = $v->email('email');
        $pass  = $v->password('password', 8);
        $name  = $v->string('display_name', true, 2, 100);
        $v->assert();

        $result = $this->auth->register(
            $email,
            $pass,
            $name,
            $request->ip(),
            $this->deviceInfo($request)
        );

        Response::success($result, 201);
    }

    public function login(Request $request, RouteContext $context): void
    {
        $v     = new Validator($request->body());
        $email = $v->email('email');
        $pass  = $v->string('password', true, 1, 200);
        $v->assert();

        $result = $this->auth->login($email, $pass, $request->ip(), $this->deviceInfo($request));

        Response::success($result);
    }

    public function logout(Request $request, RouteContext $context): void
    {
        $v   = new Validator($request->body());
        $all = $v->bool('all_devices', false);
        $v->assert();

        $this->auth->logout($context->user()->tokenId, $context->userId(), $all);

        Response::success([
            'message' => $all
                ? 'Signed out on all devices.'
                : 'Signed out.',
        ]);
    }

    public function changePassword(Request $request, RouteContext $context): void
    {
        $v       = new Validator($request->body());
        $current = $v->string('current_password', true, 1, 200);
        $next    = $v->password('new_password', 8);
        $v->assert();

        if ($current === $next) {
            Response::error(
                'VALIDATION_FAILED',
                'The new password must be different from the current one.',
                422,
                ['fields' => ['new_password' => 'Choose a different password.']]
            );
        }

        $this->auth->changePassword($context->userId(), $current, $next);

        Response::success([
            'message' => 'Password updated. Please sign in again on your other devices.',
        ]);
    }

    public function deleteAccount(Request $request, RouteContext $context): void
    {
        $v        = new Validator($request->body());
        $password = $v->string('password', true, 1, 200);
        $confirm  = $v->string('confirm', true, 1, 20);
        $v->assert();

        if (strtoupper($confirm) !== 'DELETE') {
            Response::error(
                'VALIDATION_FAILED',
                'Type DELETE in the confirm field to permanently remove your account.',
                422,
                ['fields' => ['confirm' => 'Must be the word DELETE.']]
            );
        }

        $this->auth->deleteAccount($context->userId(), $password);

        Response::success([
            'message' => 'Your account and all stored translations have been permanently deleted.',
        ]);
    }

    /**
     * A short device label shown on the user's sessions list. Truncated and
     * sanitised; never used in SQL or shell contexts.
     */
    private function deviceInfo(Request $request): string
    {
        $declared = $request->input('device');

        if (is_string($declared) && trim($declared) !== '') {
            return mb_substr(preg_replace('/[^\p{L}\p{N}\s\-_.()]/u', '', $declared) ?? '', 0, 120);
        }

        return mb_substr($request->userAgent(), 0, 120);
    }
}
