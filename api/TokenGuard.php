<?php

declare(strict_types=1);

class TokenGuard
{
    /**
     * Validates a plain-text Bearer token against the api_tokens table.
     * Returns the token row on success, null on failure.
     */
    public static function resolve(string $plainToken): ?array
    {
        if ($plainToken === '') {
            return null;
        }

        $hash = hash('sha256', $plainToken);

        $token = pdoFetchOne(
            "SELECT id, name, abilities
             FROM api_tokens
             WHERE token_hash = :hash
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1",
            [':hash' => $hash]
        );

        if ($token === null) {
            return null;
        }

        pdoExecute(
            "UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id",
            [':id' => (int) $token['id']]
        );

        return $token;
    }

    /**
     * Checks whether a resolved token row is allowed to perform the given ability.
     *
     * Ability grammar (stored as a JSON array in api_tokens.abilities):
     *   "*"              → full access to every endpoint
     *   "campaigns:*"    → every action on the "campaigns" resource
     *   "campaigns:read" → that exact ability only
     */
    public static function hasAbility(?array $token, string $required): bool
    {
        if ($token === null || $required === '') {
            return false;
        }

        $granted = $token['abilities'] ?? null;

        // Column is JSON; PDO returns it as a string.
        if (is_string($granted)) {
            $granted = json_decode($granted, true);
        }
        if (!is_array($granted)) {
            return false;
        }

        [$resource] = explode(':', $required, 2);

        foreach ($granted as $ability) {
            if (!is_string($ability)) {
                continue;
            }
            if ($ability === '*'
                || $ability === $required
                || $ability === $resource . ':*'
            ) {
                return true;
            }
        }

        return false;
    }
}
