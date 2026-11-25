<?php

declare(strict_types=1);

namespace Haryanvi\Http\Controllers;

use Haryanvi\Core\ApiException;
use Haryanvi\Core\Request;
use Haryanvi\Core\Response;
use Haryanvi\Core\RouteContext;
use Haryanvi\Core\Validator;
use Haryanvi\Repositories\DialectRepository;
use Haryanvi\Repositories\HistoryRepository;
use Haryanvi\Repositories\UserRepository;
use Haryanvi\Services\QuotaService;
use Haryanvi\Services\SettingsService;

/**
 * GET   /api/v1/settings   — app configuration (+ the caller's own prefs when authenticated)
 * PATCH /api/v1/settings   — update the caller's preferences
 *
 * Reachable without a token so a fresh install can read the app name,
 * maintenance state and legal text before sign-in.
 */
final class SettingsController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly DialectRepository $dialects = new DialectRepository(),
        private readonly HistoryRepository $history = new HistoryRepository(),
        private readonly QuotaService $quota = new QuotaService(),
    ) {
    }

    public function show(Request $request, RouteContext $context): void
    {
        $payload = [
            'app' => SettingsService::publicSettings(),
        ];

        // Optional auth: include personal settings when a valid token is sent,
        // but never fail the request if one is not.
        $user = $this->optionalUser($request);

        if ($user !== null) {
            $settings = $this->users->settings($user['id']);

            $payload['user'] = [
                'id'           => (string) $user['uuid'],
                'email'        => (string) $user['email'],
                'display_name' => (string) $user['display_name'],
            ];

            $payload['preferences'] = $this->formatPreferences($settings);
            $payload['stats']       = [
                'history_count' => $this->history->countForUser($user['id']),
            ];
        }

        Response::success($payload);
    }

    public function update(Request $request, RouteContext $context): void
    {
        $userId = $context->userId();

        $v        = new Validator($request->body());
        $dialect  = $v->string('dialect', false, 0, 40);
        $tone     = $v->string('tone', false, 0, 40);
        $script   = $v->enum('output_script', ['devanagari', 'roman'], false, '');
        $source   = $v->enum('default_source_lang', ['auto', 'hi', 'en'], false, '');
        $theme    = $v->enum('theme', ['system', 'dark', 'light'], false, '');
        $v->assert();

        $body   = $request->body();
        $fields = [];

        if ($dialect !== '') {
            $resolved = $this->dialects->findDialectByCode($dialect);

            if ($resolved === null) {
                throw ApiException::validation('Unknown dialect.', [
                    'dialect' => 'Not an available dialect code.',
                ]);
            }

            $fields['dialect_id'] = $resolved->id;
        }

        if ($tone !== '') {
            $resolved = $this->dialects->findToneByCode($tone);

            if ($resolved === null) {
                throw ApiException::validation('Unknown tone.', [
                    'tone' => 'Not an available tone code.',
                ]);
            }

            $fields['tone_code'] = $resolved->code;
        }

        if ($script !== '') {
            $fields['output_script'] = $script;
        }
        if ($source !== '') {
            $fields['default_source_lang'] = $source;
        }
        if ($theme !== '') {
            $fields['theme'] = $theme;
        }

        foreach ([
            'include_roman', 'save_history', 'cloud_sync',
            'floating_enabled', 'keyboard_haptics',
        ] as $flag) {
            if (array_key_exists($flag, $body)) {
                $fields[$flag] = $v->bool($flag) ? 1 : 0;
            }
        }

        if ($fields === []) {
            throw ApiException::validation('No recognised settings were supplied.');
        }

        $this->users->updateSettings($userId, $fields);

        // Turning history off clears what is already stored — the privacy
        // switch would be meaningless otherwise.
        $cleared = 0;
        if (array_key_exists('save_history', $fields) && $fields['save_history'] === 0) {
            $cleared = $this->history->clearAll($userId);
        }

        Response::success([
            'preferences'     => $this->formatPreferences($this->users->settings($userId)),
            'history_cleared' => $cleared,
        ]);
    }

    /** @param array<string,mixed> $settings */
    private function formatPreferences(array $settings): array
    {
        return [
            'dialect'             => $settings['dialect_code'] ?? SettingsService::getString('default_dialect_code', 'general'),
            'tone'                => (string) ($settings['tone_code'] ?? 'natural'),
            'output_script'       => (string) ($settings['output_script'] ?? 'devanagari'),
            'include_roman'       => (bool) ($settings['include_roman'] ?? false),
            'default_source_lang' => (string) ($settings['default_source_lang'] ?? 'auto'),
            'save_history'        => (bool) ($settings['save_history'] ?? true),
            'cloud_sync'          => (bool) ($settings['cloud_sync'] ?? false),
            'floating_enabled'    => (bool) ($settings['floating_enabled'] ?? false),
            'keyboard_haptics'    => (bool) ($settings['keyboard_haptics'] ?? true),
            'theme'               => (string) ($settings['theme'] ?? 'dark'),
        ];
    }

    /**
     * Resolve the bearer token if one was supplied, without requiring it.
     *
     * @return array<string,mixed>|null
     */
    private function optionalUser(Request $request): ?array
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return null;
        }

        $resolved = (new \Haryanvi\Repositories\TokenRepository())->resolve($token);

        if ($resolved === null) {
            return null;
        }

        $user = $resolved['user'];

        if ((string) ($user['status'] ?? '') !== 'active') {
            return null;
        }

        $user['id'] = (int) $user['id'];

        return $user;
    }
}
