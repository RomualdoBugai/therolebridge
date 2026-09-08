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
}
