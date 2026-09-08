-- ─── API Tokens table ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_tokens (
    id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name         VARCHAR(100)     NOT NULL COMMENT 'Human-readable label for this token',
    token_hash   CHAR(64)         NOT NULL COMMENT 'SHA-256 of the plain-text token',
    abilities    JSON                      DEFAULT NULL COMMENT 'e.g. ["*"] or ["campaigns:read"]',
    last_used_at DATETIME                  DEFAULT NULL,
    expires_at   DATETIME                  DEFAULT NULL COMMENT 'NULL = never expires',
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Seed a default token ─────────────────────────────────────────────────
-- Plain-text: bugai-api-default-token-change-me
-- Generate a new one: php -r "echo bin2hex(random_bytes(32));"
-- Then hash it: php -r "echo hash('sha256', '<your-token>');"
INSERT INTO api_tokens (name, token_hash, abilities, expires_at)
VALUES (
    'default',
    SHA2('bugai-api-default-token-change-me', 256),
    JSON_ARRAY('*'),
    NULL
)
ON DUPLICATE KEY UPDATE name = VALUES(name);
