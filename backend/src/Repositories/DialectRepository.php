<?php

declare(strict_types=1);

namespace Haryanvi\Repositories;

use Haryanvi\Core\Database;
use Haryanvi\Models\Dialect;
use Haryanvi\Models\TonePreset;

/**
 * Dialect profiles, tone presets and the curated regional lexicon.
 */
final class DialectRepository
{
    /** @return list<Dialect> */
    public function activeDialects(): array
    {
        $rows = Database::fetchAll(
            'SELECT id, code, name_en, name_hi, region_label, description, prompt_notes, is_default
             FROM dialects
             WHERE is_active = 1
             ORDER BY sort_order ASC, name_en ASC'
        );

        return array_map(static fn (array $row): Dialect => Dialect::fromRow($row), $rows);
    }

    public function findDialectByCode(string $code): ?Dialect
    {
        $row = Database::fetchOne(
            'SELECT id, code, name_en, name_hi, region_label, description, prompt_notes, is_default
             FROM dialects
             WHERE code = :code AND is_active = 1
             LIMIT 1',
            ['code' => $code]
        );

        return $row === null ? null : Dialect::fromRow($row);
    }

    public function findDialectById(int $id): ?Dialect
    {
        $row = Database::fetchOne(
            'SELECT id, code, name_en, name_hi, region_label, description, prompt_notes, is_default
             FROM dialects
             WHERE id = :id AND is_active = 1
             LIMIT 1',
            ['id' => $id]
        );

        return $row === null ? null : Dialect::fromRow($row);
    }

    /**
     * Resolution order: explicit code -> user's saved dialect -> configured
     * default -> the row flagged is_default -> first active row.
     */
    public function resolveDialect(?string $code, ?int $userDialectId, string $configuredDefault): ?Dialect
    {
        if ($code !== null && $code !== '' && $code !== 'auto') {
            $dialect = $this->findDialectByCode($code);
            if ($dialect !== null) {
                return $dialect;
            }
        }

        if ($userDialectId !== null && $userDialectId > 0) {
            $dialect = $this->findDialectById($userDialectId);
            if ($dialect !== null) {
                return $dialect;
            }
        }

        if ($configuredDefault !== '') {
            $dialect = $this->findDialectByCode($configuredDefault);
            if ($dialect !== null) {
                return $dialect;
            }
        }

        $row = Database::fetchOne(
            'SELECT id, code, name_en, name_hi, region_label, description, prompt_notes, is_default
             FROM dialects
             WHERE is_active = 1
             ORDER BY is_default DESC, sort_order ASC
             LIMIT 1'
        );

        return $row === null ? null : Dialect::fromRow($row);
    }

    /* ----------------------------------------------------------------
     | Tones
     * -------------------------------------------------------------- */

    /** @return list<TonePreset> */
    public function activeTones(): array
    {
        $rows = Database::fetchAll(
            'SELECT id, code, name_en, name_hi, description, prompt_instruction, is_default
             FROM tone_presets
             WHERE is_active = 1
             ORDER BY sort_order ASC, name_en ASC'
        );

        return array_map(static fn (array $row): TonePreset => TonePreset::fromRow($row), $rows);
    }

    public function findToneByCode(string $code): ?TonePreset
    {
        $row = Database::fetchOne(
            'SELECT id, code, name_en, name_hi, description, prompt_instruction, is_default
             FROM tone_presets
             WHERE code = :code AND is_active = 1
             LIMIT 1',
            ['code' => $code]
        );

        return $row === null ? null : TonePreset::fromRow($row);
    }

    public function resolveTone(?string $code, ?string $userTone, string $configuredDefault): ?TonePreset
    {
        foreach ([$code, $userTone, $configuredDefault] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $tone = $this->findToneByCode($candidate);
                if ($tone !== null) {
                    return $tone;
                }
            }
        }

        $row = Database::fetchOne(
            'SELECT id, code, name_en, name_hi, description, prompt_instruction, is_default
             FROM tone_presets
             WHERE is_active = 1
             ORDER BY is_default DESC, sort_order ASC
             LIMIT 1'
        );

        return $row === null ? null : TonePreset::fromRow($row);
    }

    /* ----------------------------------------------------------------
     | Vocabulary
     * -------------------------------------------------------------- */

    /**
     * Pick lexicon rows worth showing the model for this specific input.
     *
     * Strategy: prefer entries whose source term actually occurs in the text
     * (so the reference is relevant), then top up with the highest-weighted
     * general entries for the dialect. This keeps prompts small while still
     * grounding the model in authentic regional vocabulary.
     *
     * @return list<array<string,mixed>>
     */
    public function contextVocabulary(int $dialectId, string $sourceText, int $limit): array
    {
        $limit = max(1, min(120, $limit));

        $candidates = Database::fetchAll(
            'SELECT entry_type, source_lang, source_term, haryanvi_term, roman_term,
                    register, usage_note, example_source, example_haryanvi, weight
             FROM dialect_vocabulary
             WHERE is_active = 1
               AND (dialect_id = :dialect_id OR dialect_id IS NULL)
             ORDER BY (dialect_id IS NOT NULL) DESC, weight DESC, id ASC
             LIMIT 600',
            ['dialect_id' => $dialectId]
        );

        $haystack = mb_strtolower($sourceText);
        $matched  = [];
        $rest     = [];

        foreach ($candidates as $row) {
            $term = mb_strtolower(trim((string) $row['source_term']));

            if ($term !== '' && mb_strpos($haystack, $term) !== false) {
                $matched[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        // Matched entries first, then the strongest general entries.
        return array_slice([...$matched, ...$rest], 0, $limit);
    }

    /* ----------------------------------------------------------------
     | Admin CRUD
     * -------------------------------------------------------------- */

    /** @return list<array<string,mixed>> */
    public function allDialectsForAdmin(): array
    {
        return Database::fetchAll(
            'SELECT d.*, COUNT(v.id) AS vocab_count
             FROM dialects d
             LEFT JOIN dialect_vocabulary v ON v.dialect_id = d.id AND v.is_active = 1
             GROUP BY d.id
             ORDER BY d.sort_order ASC, d.name_en ASC'
        );
    }

    /** @param array<string,mixed> $data */
    public function createDialect(array $data): int
    {
        Database::execute(
            'INSERT INTO dialects (code, name_en, name_hi, region_label, description, prompt_notes, is_active, sort_order)
             VALUES (:code, :name_en, :name_hi, :region_label, :description, :prompt_notes, :is_active, :sort_order)',
            $data
        );

        return Database::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function updateDialect(int $id, array $data): void
    {
        $data['id'] = $id;

        Database::execute(
            'UPDATE dialects
             SET name_en = :name_en, name_hi = :name_hi, region_label = :region_label,
                 description = :description, prompt_notes = :prompt_notes,
                 is_active = :is_active, sort_order = :sort_order
             WHERE id = :id',
            $data
        );
    }

    public function setDefaultDialect(int $id): void
    {
        Database::transaction(static function () use ($id): void {
            Database::execute('UPDATE dialects SET is_default = 0 WHERE is_default = 1');
            Database::execute('UPDATE dialects SET is_default = 1, is_active = 1 WHERE id = :id', ['id' => $id]);
        });
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function paginateVocabulary(?int $dialectId, string $search, int $page, int $perPage): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($dialectId !== null) {
            $where[]              = 'v.dialect_id = :dialect_id';
            $params['dialect_id'] = $dialectId;
        }

        if ($search !== '') {
            // One bind per placeholder: native prepares do not reuse names.
            $where[]                 = '(v.source_term LIKE :search_src OR v.haryanvi_term LIKE :search_dst)';
            $params['search_src']    = '%' . $search . '%';
            $params['search_dst']    = '%' . $search . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $total  = (int) Database::fetchValue('SELECT COUNT(*) FROM dialect_vocabulary v ' . $whereSql, $params);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = Database::fetchAll(
            'SELECT v.*, d.name_en AS dialect_name
             FROM dialect_vocabulary v
             LEFT JOIN dialects d ON d.id = v.dialect_id
             ' . $whereSql . '
             ORDER BY v.weight DESC, v.id DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function findVocabulary(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM dialect_vocabulary WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function createVocabulary(array $data): int
    {
        Database::execute(
            'INSERT INTO dialect_vocabulary
                (dialect_id, entry_type, source_lang, source_term, haryanvi_term, roman_term,
                 register, usage_note, example_source, example_haryanvi, weight, is_active, created_by_admin)
             VALUES
                (:dialect_id, :entry_type, :source_lang, :source_term, :haryanvi_term, :roman_term,
                 :register, :usage_note, :example_source, :example_haryanvi, :weight, :is_active, :created_by_admin)',
            $data
        );

        return Database::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function updateVocabulary(int $id, array $data): void
    {
        $data['id'] = $id;

        Database::execute(
            'UPDATE dialect_vocabulary
             SET dialect_id = :dialect_id, entry_type = :entry_type, source_lang = :source_lang,
                 source_term = :source_term, haryanvi_term = :haryanvi_term, roman_term = :roman_term,
                 register = :register, usage_note = :usage_note, example_source = :example_source,
                 example_haryanvi = :example_haryanvi, weight = :weight, is_active = :is_active
             WHERE id = :id',
            $data
        );
    }

    public function deleteVocabulary(int $id): void
    {
        Database::execute('DELETE FROM dialect_vocabulary WHERE id = :id', ['id' => $id]);
    }

    /* ---- Tone admin CRUD ---- */

    /** @return list<array<string,mixed>> */
    public function allTonesForAdmin(): array
    {
        return Database::fetchAll('SELECT * FROM tone_presets ORDER BY sort_order ASC, name_en ASC');
    }

    /** @param array<string,mixed> $data */
    public function updateTone(int $id, array $data): void
    {
        $data['id'] = $id;

        Database::execute(
            'UPDATE tone_presets
             SET name_en = :name_en, name_hi = :name_hi, description = :description,
                 prompt_instruction = :prompt_instruction, is_active = :is_active, sort_order = :sort_order
             WHERE id = :id',
            $data
        );
    }

    /** @param array<string,mixed> $data */
    public function createTone(array $data): int
    {
        Database::execute(
            'INSERT INTO tone_presets (code, name_en, name_hi, description, prompt_instruction, is_active, sort_order)
             VALUES (:code, :name_en, :name_hi, :description, :prompt_instruction, :is_active, :sort_order)',
            $data
        );

        return Database::lastInsertId();
    }
}
