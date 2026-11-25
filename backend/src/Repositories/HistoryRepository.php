<?php

declare(strict_types=1);

namespace Haryanvi\Repositories;

use Haryanvi\Core\Crypto;
use Haryanvi\Core\Database;

/**
 * Translation history.
 *
 * Every read and write is scoped by `user_id`, so one account can never
 * address another account's rows even with a guessed identifier. Rows are
 * only written when the user's `save_history` setting is on.
 */
final class HistoryRepository
{
    private const PUBLIC_COLUMNS =
        'h.uuid AS id, h.source_text, h.source_lang, h.detected_lang,
         h.translated_text, h.roman_text, h.tone_code, h.output_script,
         h.is_favourite, h.source_channel, h.created_at,
         d.code AS dialect_code, d.name_en AS dialect_name';

    /**
     * @return string the new row's UUID
     */
    public function create(
        int $userId,
        string $sourceText,
        string $sourceLang,
        ?string $detectedLang,
        ?int $dialectId,
        string $toneCode,
        string $outputScript,
        string $translatedText,
        ?string $romanText,
        ?int $providerId,
        ?string $model,
        string $channel,
    ): string {
        $uuid = Crypto::uuid4();

        Database::execute(
            'INSERT INTO translation_history
                (uuid, user_id, source_text, source_lang, detected_lang, dialect_id,
                 tone_code, output_script, translated_text, roman_text,
                 provider_id, model, source_channel)
             VALUES
                (:uuid, :user_id, :source_text, :source_lang, :detected_lang, :dialect_id,
                 :tone_code, :output_script, :translated_text, :roman_text,
                 :provider_id, :model, :channel)',
            [
                'uuid'            => $uuid,
                'user_id'         => $userId,
                'source_text'     => $sourceText,
                'source_lang'     => $sourceLang,
                'detected_lang'   => $detectedLang,
                'dialect_id'      => $dialectId,
                'tone_code'       => $toneCode,
                'output_script'   => $outputScript,
                'translated_text' => $translatedText,
                'roman_text'      => $romanText,
                'provider_id'     => $providerId,
                'model'           => $model,
                'channel'         => $channel,
            ]
        );

        return $uuid;
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function paginate(
        int $userId,
        string $search,
        bool $favouritesOnly,
        int $page,
        int $perPage,
    ): array {
        $where  = ['h.user_id = :user_id'];
        $params = ['user_id' => $userId];

        if ($search !== '') {
            // Native prepared statements bind each placeholder once, so the
            // two LIKE clauses need separate names.
            $where[]                 = '(h.source_text LIKE :search_src OR h.translated_text LIKE :search_dst)';
            $params['search_src']    = '%' . $search . '%';
            $params['search_dst']    = '%' . $search . '%';
        }

        if ($favouritesOnly) {
            $where[] = 'h.is_favourite = 1';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $total = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM translation_history h ' . $whereSql,
            $params
        );

        $perPage = max(1, min(100, $perPage));
        $offset  = max(0, ($page - 1) * $perPage);

        $rows = Database::fetchAll(
            'SELECT ' . self::PUBLIC_COLUMNS . '
             FROM translation_history h
             LEFT JOIN dialects d ON d.id = h.dialect_id
             ' . $whereSql . '
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function findForUser(string $uuid, int $userId): ?array
    {
        return Database::fetchOne(
            'SELECT ' . self::PUBLIC_COLUMNS . ', h.id AS internal_id
             FROM translation_history h
             LEFT JOIN dialects d ON d.id = h.dialect_id
             WHERE h.uuid = :uuid AND h.user_id = :user_id
             LIMIT 1',
            ['uuid' => $uuid, 'user_id' => $userId]
        );
    }

    public function delete(string $uuid, int $userId): bool
    {
        return Database::execute(
            'DELETE FROM translation_history WHERE uuid = :uuid AND user_id = :user_id',
            ['uuid' => $uuid, 'user_id' => $userId]
        ) > 0;
    }

    public function clearAll(int $userId): int
    {
        return Database::execute(
            'DELETE FROM translation_history WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
    }

    public function setFavourite(string $uuid, int $userId, bool $favourite): bool
    {
        return Database::execute(
            'UPDATE translation_history SET is_favourite = :fav
             WHERE uuid = :uuid AND user_id = :user_id',
            ['fav' => $favourite ? 1 : 0, 'uuid' => $uuid, 'user_id' => $userId]
        ) > 0;
    }

    public function countForUser(int $userId): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM translation_history WHERE user_id = :id',
            ['id' => $userId]
        );
    }
}
