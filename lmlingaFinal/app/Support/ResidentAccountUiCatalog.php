<?php

namespace App\Support;

use App\Models\ResidentAccount;

/**
 * Maps chatbot ResidentAccount rows into the frozen User Management Residents UI shape.
 */
final class ResidentAccountUiCatalog
{
    /** @var list<string> */
    public const ALLOWED_ZONES = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return ResidentAccount::query()
            ->orderBy('account_id')
            ->get()
            ->map(fn (ResidentAccount $account): array => self::toUiArray($account))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $publicId): ?array
    {
        $account = self::findModel($publicId);

        return $account === null ? null : self::toUiArray($account);
    }

    public static function findModel(string $publicId): ?ResidentAccount
    {
        $accountId = self::parsePublicId($publicId);

        if ($accountId === null) {
            return null;
        }

        return ResidentAccount::query()
            ->whereKey($accountId)
            ->first();
    }

    public static function parsePublicId(string $publicId): ?int
    {
        if (preg_match('/^ra-(\d+)$/', $publicId, $matches) !== 1) {
            return null;
        }

        $accountId = (int) $matches[1];

        return $accountId > 0 ? $accountId : null;
    }

    public static function publicId(int $accountId): string
    {
        return 'ra-'.$accountId;
    }

    /**
     * Persist Admin "Zone N" selections as the same digit chatbot registration stores.
     */
    public static function persistZone(string $zone): string
    {
        if (preg_match('/(\d+)/', $zone, $matches) === 1) {
            return $matches[1];
        }

        return trim($zone);
    }

    /**
     * @return array{
     *     id: string,
     *     first_name: string,
     *     middle_name: string,
     *     last_name: string,
     *     name: string,
     *     zone: string,
     *     email: string
     * }
     */
    public static function toUiArray(ResidentAccount $account): array
    {
        $first = trim((string) $account->first_name);
        $middle = trim((string) $account->middle_name);
        $last = trim((string) $account->last_name);
        $name = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== '')));

        return [
            'id' => self::publicId((int) $account->account_id),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'name' => $name,
            'zone' => self::displayZone(self::resolveZone($account)),
            'email' => (string) $account->email,
        ];
    }

    private static function resolveZone(ResidentAccount $account): string
    {
        return trim((string) $account->zone_purok);
    }

    public static function displayZone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/(\d+)/', $raw, $matches) === 1) {
            return 'Zone '.$matches[1];
        }

        return $raw;
    }
}
