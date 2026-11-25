<?php

declare(strict_types=1);

namespace Haryanvi\Models;

/**
 * A conversational register. `promptInstruction` is injected into the prompt.
 */
final class TonePreset
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $nameEn,
        public readonly string $nameHi,
        public readonly string $description,
        public readonly string $promptInstruction,
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
            (string) ($row['description'] ?? ''),
            (string) $row['prompt_instruction'],
            (bool) ($row['is_default'] ?? false),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code'        => $this->code,
            'name_en'     => $this->nameEn,
            'name_hi'     => $this->nameHi,
            'description' => $this->description,
            'is_default'  => $this->isDefault,
        ];
    }
}
