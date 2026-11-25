<?php

declare(strict_types=1);

namespace Haryanvi\Http\Controllers;

use Haryanvi\Core\ApiException;
use Haryanvi\Core\RateLimiter;
use Haryanvi\Core\Request;
use Haryanvi\Core\Response;
use Haryanvi\Core\RouteContext;
use Haryanvi\Core\Validator;
use Haryanvi\Repositories\DialectRepository;
use Haryanvi\Repositories\FeedbackRepository;
use Haryanvi\Repositories\HistoryRepository;

/**
 * POST /api/v1/feedback
 *
 * The user-facing half of translation quality control: a report lands in the
 * admin review queue, where a reviewer can promote the suggested wording into
 * the curated lexicon that future prompts draw on.
 */
final class FeedbackController
{
    private const REASONS = [
        'wrong_meaning', 'wrong_dialect', 'unnatural', 'script_issue', 'offensive', 'other',
    ];

    public function __construct(
        private readonly FeedbackRepository $feedback = new FeedbackRepository(),
        private readonly HistoryRepository $history = new HistoryRepository(),
        private readonly DialectRepository $dialects = new DialectRepository(),
    ) {
    }

    public function store(Request $request, RouteContext $context): void
    {
        $userId = $context->userId();

        // Keep the review queue usable: 20 reports per hour per account.
        RateLimiter::hit('feedback.hour', (string) $userId, 20, 3600);

        $v          = new Validator($request->body());
        $reason     = $v->enum('reason', self::REASONS, true);
        $historyId  = $v->string('history_id', false, 0, 36);
        $source     = $v->string('source_text', false, 0, 5000);
        $translated = $v->string('translated_text', false, 0, 5000);
        $suggested  = $v->string('suggested_text', false, 0, 5000);
        $comment    = $v->string('comment', false, 0, 1000);
        $dialect    = $v->string('dialect', false, 0, 40);
        $v->assert();

        $internalHistoryId = null;
        $dialectId         = $dialect !== '' ? $this->dialects->findDialectByCode($dialect)?->id : null;

        // Prefer the stored row: it is authoritative and saves the client
        // from re-sending text.
        if ($historyId !== '') {
            $row = $this->history->findForUser($historyId, $userId);

            if ($row === null) {
                throw ApiException::notFound('That translation is not in your history.');
            }

            $internalHistoryId = (int) $row['internal_id'];
            $source            = $source !== '' ? $source : (string) $row['source_text'];
            $translated        = $translated !== '' ? $translated : (string) $row['translated_text'];

            if ($dialectId === null && $row['dialect_code'] !== null) {
                $dialectId = $this->dialects->findDialectByCode((string) $row['dialect_code'])?->id;
            }
        }

        if ($source === '' || $translated === '') {
            throw ApiException::validation(
                'Send either a history_id, or both source_text and translated_text.',
                [
                    'source_text'     => $source === '' ? 'Required when history_id is absent.' : '',
                    'translated_text' => $translated === '' ? 'Required when history_id is absent.' : '',
                ]
            );
        }

        $id = $this->feedback->create(
            $userId,
            $internalHistoryId,
            $dialectId,
            $source,
            $translated,
            $suggested === '' ? null : $suggested,
            $reason,
            $comment === '' ? null : $comment
        );

        Response::success([
            'feedback_id' => $id,
            'message'     => 'Thanks — your report will help improve Haryanvi translations.',
        ], 201);
    }
}
