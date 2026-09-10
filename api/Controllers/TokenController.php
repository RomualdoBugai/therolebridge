<?php

declare(strict_types=1);

class TokenController
{
    private const MAX_NAME_LENGTH = 100;

    /** Ability strings must be "*", "resource:*" or "resource:action". */
    private const ABILITY_PATTERN = '/^(\*|[a-z0-9\-]+:(\*|[a-z0-9\-]+))$/';

    /**
     * POST /tokens
     * Creates a new API token. The plain-text token is returned ONCE in the
     * response; only its SHA-256 hash is persisted, so it cannot be recovered later.
     */
    public function store(Request $request, array $_params = []): void
    {
        $name      = $request->string('name');
        $abilities = $this->normalizeAbilities($request->input('abilities'));
        $expiresAt = $request->string('expires_at');

        $errors = $this->validate($name, $abilities, $expiresAt);

        if (!empty($errors)) {
            Response::json(['success' => false, 'message' => 'Validation error.', 'errors' => $errors], 422);
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);

        try {
            $id = pdoInsertGetId(
                "INSERT INTO api_tokens (name, token_hash, abilities, expires_at, created_at)
                 VALUES (:name, :token_hash, :abilities, :expires_at, NOW())",
                [
                    ':name'       => $name,
                    ':token_hash' => $tokenHash,
                    ':abilities'  => json_encode(array_values($abilities)),
                    ':expires_at' => $expiresAt !== '' ? $expiresAt : null,
                ]
            );
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                Response::json(['success' => false, 'message' => 'Token collision, please retry.'], 409);
            }
            throw $e;
        }

        Response::json([
            'success' => true,
            'message' => 'Token created. Store it now — it will not be shown again.',
            'data'    => [
                'id'         => $id,
                'name'       => $name,
                'abilities'  => array_values($abilities),
                'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                'token'      => $plainToken,
            ],
        ], 201);
    }

    /**
     * Accepts abilities as a JSON array (["leads:read"]) or a comma-separated
     * string ("leads:read,campaigns:read") and returns a de-duplicated string list.
     */
    private function normalizeAbilities($raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $abilities = [];
        foreach ($raw as $ability) {
            $ability = strtolower(trim((string) $ability));
            if ($ability !== '') {
                $abilities[$ability] = $ability;
            }
        }

        return $abilities;
    }

    private function validate(string $name, array $abilities, string $expiresAt): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = "Field 'name' is required.";
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors[] = "Field 'name' exceeds max length of " . self::MAX_NAME_LENGTH . ' characters.';
        }

        if (empty($abilities)) {
            $errors[] = "Field 'abilities' is required (e.g. [\"*\"] or [\"leads:read\"]).";
        } else {
            foreach ($abilities as $ability) {
                if (!preg_match(self::ABILITY_PATTERN, $ability)) {
                    $errors[] = "Invalid ability '{$ability}'. Use '*', 'resource:*' or 'resource:action'.";
                }
            }
        }

        if ($expiresAt !== '') {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $expiresAt);
            if (!$dt || $dt->format('Y-m-d H:i:s') !== $expiresAt) {
                $errors[] = "Field 'expires_at' must be formatted as 'Y-m-d H:i:s' (e.g. 2026-12-31 23:59:59).";
            }
        }

        return $errors;
    }
}
