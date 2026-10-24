-- A learner's Modultest letter or blog post sent for review, with the review and what it
-- cost. The rows are also the count each learner's daily allowance is measured against.

CREATE TABLE writing_checks (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- A signed-in learner is counted by account, a guest by browser key; never both.
    user_id       BIGINT UNSIGNED NULL,
    anon_key      CHAR(32) COLLATE utf8mb4_0900_as_cs NULL,
    task_id       VARCHAR(16) COLLATE utf8mb4_0900_as_cs NOT NULL,
    text          MEDIUMTEXT NOT NULL,
    word_count    SMALLINT UNSIGNED NOT NULL,
    feedback      JSON NOT NULL,
    model         VARCHAR(64) NOT NULL,
    input_tokens  INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_wc_user (user_id, created_at),
    KEY ix_wc_anon (anon_key, created_at),
    KEY ix_wc_created (created_at),
    CONSTRAINT fk_wc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
