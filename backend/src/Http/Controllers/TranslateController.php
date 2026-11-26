<?php

declare(strict_types=1);

namespace Haryanvi\Http\Controllers;

use Haryanvi\Core\ApiException;
use Haryanvi\Core\Request;
use Haryanvi\Core\Response;
use Haryanvi\Core\RouteContext;
use Haryanvi\Core\Validator;
use Haryanvi\Models\TranslationRequest;
use Haryanvi\Models\TranslationResult;
use Haryanvi\Repositories\DialectRepository;
use Haryanvi\Repositories\HistoryRepository;
use Haryanvi\Repositories\UserRepository;
use Haryanvi\Services\LanguageDetector;
use Haryanvi\Services\QuickTranslator;
use Haryanvi\Services\QuotaService;
use Haryanvi\Services\SettingsService;
use Haryanvi\Services\TranslationService;

/**
 * POST /api/v1/translate
 *
 * The one endpoint that spends money, so it is also the most heavily guarded:
 * auth -> per-minute/hour throttle (middleware) -> quota -> length check ->
 * translate -> optional history write.
 */
final class TranslateController
{
    private const CHANNELS = ['app', 'keyboard', 'floating', 'share', 'api'];

    public function __construct(
        private readonly TranslationService $translator = new TranslationService(),
        private readonly DialectRepository $dialects = new DialectRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly HistoryRepository $history = new HistoryRepository(),
        private readonly QuotaService $quota = new QuotaService(),
    ) {
    }

    public function translate(Request $request, RouteContext $context): void
    {
        $user     = $context->user();
        $maxChars = SettingsService::maxInputChars();

        $v    = new Validator($request->body());
        $text = $v->string('text', true, 1, $maxChars);
        $sourceLang   = $v->enum('source_lang', ['auto', 'hi', 'en'], false, 'auto');
        $dialectCode  = $v->string('dialect', false, 0, 40);
        $toneCode     = $v->string('tone', false, 0, 40);
        $outputScript = $v->enum('output_script', ['devanagari', 'roman'], false, '');
        $channel      = $v->enum('channel', self::CHANNELS, false, 'app');
        // 'ai' = model translation (default); 'quick' = instant rule-based, no AI.
        $mode         = $v->enum('mode', ['ai', 'quick'], false, 'ai');
        $saveHistory  = $request->input('save_history');
        $v->assert();

        if (trim($text) === '') {
            throw ApiException::validation('There is nothing to translate.', [
                'text' => 'Enter some text first.',
            ]);
        }

        // Server-side quota. Checked before the AI call, never trusted from
        // the client. Quick mode costs nothing, so it is not counted.
        if ($mode === 'ai') {
            $this->quota->assertWithinQuota($user);
        }

        $settings = $this->users->settings($user->id);

        $dialect = $this->dialects->resolveDialect(
            $dialectCode !== '' ? $dialectCode : null,
            isset($settings['dialect_id']) && $settings['dialect_id'] !== null ? (int) $settings['dialect_id'] : null,
            SettingsService::getString('default_dialect_code', 'general')
        );

        if ($dialect === null) {
            throw ApiException::server(
                'No dialect profiles are configured. Load backend/database/seed.sql.'
            );
        }

        $tone = $this->dialects->resolveTone(
            $toneCode !== '' ? $toneCode : null,
            isset($settings['tone_code']) ? (string) $settings['tone_code'] : null,
            SettingsService::getString('default_tone_code', 'natural')
        );

        if ($tone === null) {
            throw ApiException::server(
                'No tone presets are configured. Load backend/database/seed.sql.'
            );
        }

        $script = $outputScript !== ''
            ? $outputScript
            : (string) ($settings['output_script'] ?? 'devanagari');

        $includeRoman = $script === 'roman' || (bool) ($settings['include_roman'] ?? false);
        $detected     = $sourceLang === 'auto' ? LanguageDetector::detect($text) : $sourceLang;

        $translationRequest = new TranslationRequest(
            $text,
            $sourceLang,
            $detected,
            $dialect,
            $tone,
            $script,
            $includeRoman,
            $channel
        );

        $engine = 'ai';

        if ($mode === 'quick') {
            $result = $this->quickResult($text, $detected);

            if ($result === null) {
                throw ApiException::validation(
                    'Instant mode works with Hindi or Hinglish text only. Turn Instant mode off to translate English.',
                    ['text' => 'Instant mode cannot translate English.']
                );
            }

            $engine = 'quick';
        } else {
            try {
                $result = $this->translator->translate($translationRequest, $user->id);
            } catch (ApiException $e) {
                // AI unavailable: fall back to the instant engine for Hindi
                // and Hinglish so the user still gets an answer. English has
                // no rule-based path, so the original error stands.
                if (!in_array($e->errorCode(), ['AI_PROVIDER_FAILED', 'AI_PROVIDER_NOT_CONFIGURED'], true)) {
                    throw $e;
                }

                $result = $this->quickResult($text, $detected);

                if ($result === null) {
                    throw $e;
                }

                $engine = 'quick';
            }
        }

        /* ---- History: only with consent ------------------------------- */
        $userWantsHistory = (bool) ($settings['save_history'] ?? true);

        // A per-request opt-out is honoured; a per-request opt-in is not
        // allowed to override the account-level privacy setting.
        if (is_bool($saveHistory) || is_numeric($saveHistory)) {
            $userWantsHistory = $userWantsHistory && (bool) $saveHistory;
        }

        $historyId = null;

        if ($userWantsHistory) {
            $historyId = $this->history->create(
                $user->id,
                $text,
                $sourceLang,
                $result->detectedLang,
                $dialect->id,
                $tone->code,
                $script,
                $result->translatedText,
                $result->romanText,
                $engine === 'quick' ? null : $result->providerId,
                $result->model,
                $channel
            );
        }

        Response::success(
            [
                'translated_text' => $result->translatedText,
                'roman_text'      => $result->romanText,
                'primary_text'    => $script === 'roman' && $result->romanText !== null
                    ? $result->romanText
                    : $result->translatedText,
                'source_text'     => $text,
                'detected_lang'   => $result->detectedLang,
                'output_script'   => $script,
                'dialect'         => $dialect->toArray(),
                'tone'            => $tone->toArray(),
                'history_id'      => $historyId,
                'saved_to_history' => $historyId !== null,
                'engine'          => $engine,
            ],
            200,
            [
                'latency_ms' => $result->latencyMs,
                'usage'      => $this->quota->snapshot($user),
            ]
        );
    }

    /**
     * Run the rule-based engine and wrap it in the same result type the AI
     * path returns, so the rest of the controller stays identical.
     */
    private function quickResult(string $text, string $detected): ?TranslationResult
    {
        $started = microtime(true);
        $quick   = QuickTranslator::translate($text, $detected);

        if ($quick === null) {
            return null;
        }

        return new TranslationResult(
            $quick['haryanvi'],
            $quick['roman'],
            $detected,
            0,
            'Instant (rules)',
            'quick-rules',
            0,
            0,
            (int) round((microtime(true) - $started) * 1000),
            1,
        );
    }
}
