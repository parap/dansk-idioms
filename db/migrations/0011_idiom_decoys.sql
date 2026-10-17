-- Wrong answers written for one idiom out of its own words, so the word a learner
-- recognises stops pointing at the answer. Loaded from content/idioms/decoys.json;
-- two "also correct" reports retire one, and a reload never revives it.

CREATE TABLE idiom_decoys (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    idiom_id     BIGINT UNSIGNED NOT NULL,
    lang_code    CHAR(5)      NOT NULL,
    text         VARCHAR(255) NOT NULL,
    text_norm    VARCHAR(255) COLLATE utf8mb4_0900_as_cs NOT NULL,
    shape        ENUM('verbal','nominal','adverbial','interjection','other') NULL,
    word_count   TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    char_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    report_count INT          NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_decoy (idiom_id, lang_code, text_norm),
    CONSTRAINT fk_decoy_idiom FOREIGN KEY (idiom_id) REFERENCES idioms(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
