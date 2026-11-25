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

/**
 * GET    /api/v1/history           — list, search, filter favourites
 * POST   /api/v1/history           — upload an entry (offline / cloud sync)
 * PATCH  /api/v1/history/{id}      — toggle favourite
 * DELETE /api/v1/history/{id}      — delete one entry
 * DELETE /api/v1/history           — clear everything
 *
 * Rows are addressed by UUID and every query is scoped to the caller, so one
 * account cannot reach another's data.
 */
final class HistoryController
{
    public function __construct(
        private readonly HistoryRepository $history = new HistoryRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly DialectRepository $dialects = new DialectRepository(),
    ) {
    }

    public function index(Request $request, RouteContext $context): void
    {
        $userId  = $context->userId();
        $page    = max(1, $request->queryInt('page', 1));
        $perPage = min(100, max(1, $request->queryInt('per_page', 25)));
        $search  = mb_substr($request->queryString('q'), 0, 200);
        $favOnly = in_array(strtolower($request->queryString('favourites')), ['1', 'true', 'yes'], true);

        $result = $this->history->paginate($userId, $search, $favOnly, $page, $perPage);

        Response::success(
            ['items' => array_map([$this, 'formatRow'], $result['rows'])],
            200,
            [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ]
        );
    }

    /**
     * Accepts an entry the client already has — used when the app translated
     * while offline-queued, or when turning cloud sync on.
     *
     * This endpoint stores text the client supplies; it does not translate,
     * so it consumes no AI quota.
     */
    public function store(Request $request, RouteContext $context): void
    {
        $userId   = $context->userId();
        $settings = $this->users->settings($userId);

        if (!(bool) ($settings['save_history'] ?? true)) {
            throw ApiException::forbidden(
                'History is disabled in your privacy settings. Enable it before uploading entries.'
            );
        }

        $v          = new Validator($request->body());
        $source     = $v->string('source_text', true, 1, 5000);
        $translated = $v->string('translated_text', true, 1, 5000);
        $roman      = $v->string('roman_text', false, 0, 5000);
        $sourceLang = $v->enum('source_lang', ['auto', 'hi', 'en', 'hinglish'], false, 'auto');
        $script     = $v->enum('output_script', ['devanagari', 'roman'], false, 'devanagari');
        $channel    = $v->enum('channel', ['app', 'keyboard', 'floating', 'share', 'api'], false, 'app');
        $dialect    = $v->string('dialect', false, 0, 40);
        $tone       = $v->string('tone', false, 0, 40);
        $v->assert();

        $dialectModel = $dialect !== '' ? $this->dialects->findDialectByCode($dialect) : null;

        $uuid = $this->history->create(
            $userId,
            $source,
            $sourceLang,
            null,
            $dialectModel?->id ?? (isset($settings['dialect_id']) ? (int) $settings['dialect_id'] : null),
            $tone !== '' ? $tone : (string) ($settings['tone_code'] ?? 'natural'),
            $script,
            $translated,
            $roman === '' ? null : $roman,
            null,
            null,
            $channel
        );

        $row = $this->history->findForUser($uuid, $userId);

        Response::success(
            ['item' => $row === null ? ['id' => $uuid] : $this->formatRow($row)],
            201
        );
    }

    public function update(Request $request, RouteContext $context): void
    {
        $userId = $context->userId();
        $uuid   = (string) $context->param('id', '');

        $this->assertUuid($uuid);

        $body = $request->body();

        if (!array_key_exists('is_favourite', $body)) {
            throw ApiException::validation('Nothing to update.', [
                'is_favourite' => 'Supply true or false.',
            ]);
        }

        $v         = new Validator($body);
        $favourite = $v->bool('is_favourite');
        $v->assert();

        if (!$this->history->setFavourite($uuid, $userId, $favourite)) {
            throw ApiException::notFound('That translation is not in your history.');
        }

        $row = $this->history->findForUser($uuid, $userId);

        Response::success([
            'item' => $row === null ? null : $this->formatRow($row),
        ]);
    }

    public function destroy(Request $request, RouteContext $context): void
    {
        $userId = $context->userId();
        $uuid   = (string) $context->param('id', '');

        $this->assertUuid($uuid);

        if (!$this->history->delete($uuid, $userId)) {
            throw ApiException::notFound('That translation is not in your history.');
        }

        Response::success(['message' => 'Translation deleted.']);
    }

    public function clear(Request $request, RouteContext $context): void
    {
        $deleted = $this->history->clearAll($context->userId());

        Response::success([
            'deleted' => $deleted,
            'message' => $deleted === 0
                ? 'Your history was already empty.'
                : sprintf('Deleted %d saved translation%s.', $deleted, $deleted === 1 ? '' : 's'),
        ]);
    }

    /** @param array<string,mixed> $row */
    private function formatRow(array $row): array
    {
        return [
            'id'              => (string) $row['id'],
            'source_text'     => (string) $row['source_text'],
            'translated_text' => (string) $row['translated_text'],
            'roman_text'      => $row['roman_text'] === null ? null : (string) $row['roman_text'],
            'source_lang'     => (string) $row['source_lang'],
            'detected_lang'   => $row['detected_lang'] === null ? null : (string) $row['detected_lang'],
            'dialect'         => $row['dialect_code'] === null ? null : (string) $row['dialect_code'],
            'dialect_name'    => $row['dialect_name'] === null ? null : (string) $row['dialect_name'],
            'tone'            => (string) $row['tone_code'],
            'output_script'   => (string) $row['output_script'],
            'is_favourite'    => (bool) $row['is_favourite'],
            'channel'         => (string) $row['source_channel'],
            'created_at'      => (string) $row['created_at'],
        ];
    }

    private function assertUuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw ApiException::notFound('That translation is not in your history.');
        }
    }
}
