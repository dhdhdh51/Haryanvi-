<?php

declare(strict_types=1);

namespace Haryanvi\Models;

/**
 * The caller behind a validated bearer token.
 */
final class AuthenticatedUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $status,
        public readonly ?int $dailyLimit,
        public readonly ?int $monthlyLimit,
        public readonly int $tokenId,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row, int $tokenId): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['uuid'],
            (string) $row['email'],
            (string) $row['display_name'],
            (string) $row['status'],
            isset($row['daily_limit']) && $row['daily_limit'] !== null ? (int) $row['daily_limit'] : null,
            isset($row['monthly_limit']) && $row['monthly_limit'] !== null ? (int) $row['monthly_limit'] : null,
            $tokenId,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->uuid,
            'email'        => $this->email,
            'display_name' => $this->displayName,
            'status'       => $this->status,
        ];
    }
}
