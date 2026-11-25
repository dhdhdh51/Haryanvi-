<?php

declare(strict_types=1);

namespace Haryanvi\Models;

/**
 * A regional Haryanvi profile. `promptNotes` is linguistic guidance written
 * for the model and is injected verbatim into the system prompt.
 */
final class Dialect
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $nameEn,
        public readonly string $nameHi,
        public readonly string $regionLabel,
        public readonly string $description,
        public readonly string $promptNotes,
        public readonly bool $isDefault,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['name_en'],
            (string) $row['name_hi'],
            (string) $row['region_label'],
            (string) ($row['description'] ?? ''),
            (string) ($row['prompt_notes'] ?? ''),
            (bool) ($row['is_default'] ?? false),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code'         => $this->code,
            'name_en'      => $this->nameEn,
            'name_hi'      => $this->nameHi,
            'region_label' => $this->regionLabel,
            'description'  => $this->description,
            'is_default'   => $this->isDefault,
        ];
    }
}
