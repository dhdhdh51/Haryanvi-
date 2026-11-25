<?php

declare(strict_types=1);

namespace Haryanvi\Repositories;

use Haryanvi\Core\Database;

/**
 * Translation quality reports.
 *
 * Feedback is the loop that turns user corrections into curated vocabulary:
 * an admin reviews a report and can promote the suggested wording straight
 * into `dialect_vocabulary`, where it then shapes future prompts.
 */
final class FeedbackRepository
{
    public function create(
        ?int $userId,
        ?int $historyId,
        ?int $dialectId,
        string $sourceText,
        string $translatedText,
        ?string $suggestedText,
        string $reason,
        ?string $comment,
    ): int {
        Database::execute(
            'INSERT INTO translation_feedback
                (user_id, history_id, dialect_id, source_text, translated_text,
                 suggested_text, reason, comment)
             VALUES
                (:user_id, :history_id, :dialect_id, :source_text, :translated_text,
                 :suggested_text, :reason, :comment)',
            [
                'user_id'         => $userId,
                'history_id'      => $historyId,
                'dialect_id'      => $dialectId,
                'source_text'     => $sourceText,
                'translated_text' => $translatedText,
                'suggested_text'  => $suggestedText,
                'reason'          => $reason,
                'comment'         => $comment,
            ]
        );

        return Database::lastInsertId();
    }

    public function countForUserSince(int $userId, int $hours): int
    {
        return (int) Database::fetchValue(
            sprintf(
                'SELECT COUNT(*) FROM translation_feedback
                 WHERE user_id = :id AND created_at >= (UTC_TIMESTAMP() - INTERVAL %d HOUR)',
                max(1, min(720, $hours))
            ),
            ['id' => $userId]
        );
    }

    /* ----------------------------------------------------------------
     | Admin review queue
     * -------------------------------------------------------------- */

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function paginate(string $status, int $page, int $perPage): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (in_array($status, ['new', 'reviewed', 'approved', 'rejected'], true)) {
            $where[]          = 'f.status = :status';
            $params['status'] = $status;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $total   = (int) Database::fetchValue('SELECT COUNT(*) FROM translation_feedback f ' . $whereSql, $params);
        $perPage = max(1, min(100, $perPage));
        $offset  = max(0, ($page - 1) * $perPage);

        $rows = Database::fetchAll(
            'SELECT f.*, d.name_en AS dialect_name, d.code AS dialect_code,
                    u.email AS user_email, a.username AS reviewer
             FROM translation_feedback f
             LEFT JOIN dialects d    ON d.id = f.dialect_id
             LEFT JOIN users u       ON u.id = f.user_id
             LEFT JOIN admin_users a ON a.id = f.reviewed_by
             ' . $whereSql . '
             ORDER BY FIELD(f.status, \'new\', \'reviewed\', \'approved\', \'rejected\'), f.created_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT f.*, d.code AS dialect_code, d.name_en AS dialect_name
             FROM translation_feedback f
             LEFT JOIN dialects d ON d.id = f.dialect_id
             WHERE f.id = :id',
            ['id' => $id]
        );
    }

    public function setStatus(int $id, string $status, int $adminId, ?string $note, ?int $vocabId = null): void
    {
        Database::execute(
            'UPDATE translation_feedback
             SET status = :status, reviewed_by = :admin, reviewed_at = UTC_TIMESTAMP(),
                 review_note = :note, applied_vocab_id = COALESCE(:vocab_id, applied_vocab_id)
             WHERE id = :id',
            [
                'status'    => $status,
                'admin'     => $adminId,
                'note'      => $note,
                'vocab_id'  => $vocabId,
                'id'        => $id,
            ]
        );
    }

    public function countByStatus(string $status): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM translation_feedback WHERE status = :status',
            ['status' => $status]
        );
    }

    /** @return list<array{reason:string,total:int}> */
    public function reasonBreakdown(): array
    {
        /** @var list<array{reason:string,total:int}> $rows */
        $rows = Database::fetchAll(
            'SELECT reason, COUNT(*) AS total
             FROM translation_feedback
             GROUP BY reason
             ORDER BY total DESC'
        );

        return $rows;
    }
}
