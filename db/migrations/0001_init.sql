-- ---------------------------------------------------------------------------
-- Danish idiom trainer -- initial schema
--
-- COLLATION NOTE (important, do not "simplify" this away):
-- MySQL 8's default utf8mb4_0900_ai_ci is ACCENT-INSENSITIVE: it treats
-- 'a' = 'aa' = 'a', 'o' = 'oe', 'ae' = 'ae'. A UNIQUE index on a Danish term
-- under that collation refuses to store "har" once "har" exists -- silent data
-- loss on exactly the characters this project is about. Every normalized /
-- unique column below is therefore explicitly utf8mb4_0900_as_cs
-- (accent-sensitive, case-sensitive). Case folding happens in PHP via
-- mb_strtolower before insert. Display columns keep the DB default so that
-- search stays forgiving.
-- ---------------------------------------------------------------------------

CREATE TABLE languages (
    code        CHAR(5)      NOT NULL PRIMARY KEY,
    name_en     VARCHAR(64)  NOT NULL,
    name_native VARCHAR(64)  NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  SMALLINT     NOT NULL DEFAULT 0
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

INSERT INTO languages (code, name_en, name_native, sort_order) VALUES
    ('da', 'Danish',  'Dansk',    1),
    ('ru', 'Russian', 'Русский',  2),
    ('en', 'English', 'English',  3);

CREATE TABLE sources (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind        ENUM('telegram_group','telegram_channel','manual','other') NOT NULL,
    external_id VARCHAR(64)  NULL,
    username    VARCHAR(64)  NULL,
    title       VARCHAR(255) NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_source (kind, external_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE import_runs (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id      INT UNSIGNED NOT NULL,
    file_name      VARCHAR(255) NOT NULL,
    file_hash      CHAR(64)     NOT NULL,
    parser_version SMALLINT     NOT NULL,
    messages_seen  INT          NOT NULL DEFAULT 0,
    entries_seen   INT          NOT NULL DEFAULT 0,
    idioms_created INT          NOT NULL DEFAULT 0,
    idioms_updated INT          NOT NULL DEFAULT 0,
    needs_review   INT          NOT NULL DEFAULT 0,
    started_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at    DATETIME     NULL,
    stats          JSON         NULL,
    CONSTRAINT fk_run_source FOREIGN KEY (source_id) REFERENCES sources(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE messages (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id     INT UNSIGNED NOT NULL,
    tg_message_id BIGINT       NOT NULL,
    from_name     VARCHAR(255) NULL,           -- private group: keep authorship
    from_id       VARCHAR(64)  NULL,
    posted_at     DATETIME     NOT NULL,       -- UTC
    posted_at_raw VARCHAR(32)  NOT NULL,       -- export's naive local string, verbatim
    text_plain    MEDIUMTEXT   NOT NULL,       -- flattened, ZWSP PRESERVED
    preamble      TEXT         NULL,           -- text before the first ZWSP
    entity_map    JSON         NULL,           -- [{type, offsetChars, lenChars}]
    raw_json      JSON         NULL,
    content_hash  CHAR(64) COLLATE utf8mb4_0900_as_cs NOT NULL,
    entry_count   SMALLINT     NOT NULL DEFAULT 0,
    imported_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_msg (source_id, tg_message_id),
    KEY ix_posted (posted_at),
    KEY ix_hash (content_hash),
    CONSTRAINT fk_msg_source FOREIGN KEY (source_id) REFERENCES sources(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Permanent audit trail of every parsed chunk. Re-parsing UPDATEs, never destroys.
CREATE TABLE raw_entries (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id        BIGINT UNSIGNED NOT NULL,
    entry_index       SMALLINT     NOT NULL,
    raw_text          TEXT         NOT NULL,
    term_guess        VARCHAR(255) NULL,
    term_note_guess   VARCHAR(255) NULL,
    explanation_guess MEDIUMTEXT   NULL,
    separator_kind    ENUM('em_dash','en_dash','colon','hyphen','newline','bold_span','none') NULL,
    strategy          ENUM('bold','script','separator','newline','fallback') NULL,
    confidence        DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    -- 'fixed' means a human edited it: reparse must never overwrite these.
    status            ENUM('pending','auto_accepted','needs_review','rejected','fixed')
                        NOT NULL DEFAULT 'pending',
    idiom_id          BIGINT UNSIGNED NULL,
    parser_version    SMALLINT     NOT NULL,
    signals           JSON         NULL,
    content_hash      CHAR(64) COLLATE utf8mb4_0900_as_cs NOT NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_entry (message_id, entry_index),
    KEY ix_review (status, confidence),
    KEY ix_idiom (idiom_id),
    CONSTRAINT fk_entry_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE idioms (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lang_code        CHAR(5)      NOT NULL DEFAULT 'da',
    term             VARCHAR(255) NOT NULL,
    term_norm        VARCHAR(255) COLLATE utf8mb4_0900_as_cs NOT NULL,
    term_note        VARCHAR(255) NULL,
    kind             ENUM('idiom','phrase','collocation','word','particle',
                          'proverb','borrowed','other') NOT NULL DEFAULT 'other',
    register         ENUM('neutral','colloquial','slang','vulgar','literary') NULL,
    shape            ENUM('verbal','nominal','adverbial','interjection','other') NULL,
    is_published     TINYINT(1)   NOT NULL DEFAULT 0,
    quality_score    DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    first_message_id BIGINT UNSIGNED NULL,
    seen_count       INT          NOT NULL DEFAULT 1,
    times_asked      INT          NOT NULL DEFAULT 0,
    times_correct    INT          NOT NULL DEFAULT 0,
    rand_key         DOUBLE       NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_term_norm (lang_code, term_norm),
    KEY ix_pub (is_published, kind),
    KEY ix_rand (is_published, rand_key),
    FULLTEXT KEY ft_term (term)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE idiom_translations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    idiom_id    BIGINT UNSIGNED NOT NULL,
    lang_code   CHAR(5)      NOT NULL,
    text        VARCHAR(255) NOT NULL,
    text_norm   VARCHAR(255) COLLATE utf8mb4_0900_as_cs NOT NULL,
    sense_type  ENUM('idiomatic','literal','gloss') NOT NULL DEFAULT 'idiomatic',
    -- 1 = primary, NULL = not primary. NEVER 0: uq_primary below relies on
    -- MySQL ignoring NULLs in unique indexes, so any number of non-primary rows
    -- coexist while only one primary can exist per (idiom, lang).
    is_primary  TINYINT(1)   NULL DEFAULT NULL,
    quiz_usable TINYINT(1)   NOT NULL DEFAULT 0,
    shape       ENUM('verbal','nominal','adverbial','interjection','other') NULL,
    word_count  TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    char_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    source      ENUM('import','manual','llm') NOT NULL DEFAULT 'import',
    confidence  DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tr (idiom_id, lang_code, text_norm),
    -- Exactly one primary per (idiom, lang). A STORED generated column is the
    -- other way to express this, but MySQL refuses ON DELETE CASCADE on a
    -- foreign key over a column that a stored generated column reads, and the
    -- cascade matters more than the tidier expression.
    UNIQUE KEY uq_primary (idiom_id, lang_code, is_primary),
    KEY ix_pool (lang_code, quiz_usable, sense_type, word_count),
    CONSTRAINT fk_tr_idiom FOREIGN KEY (idiom_id) REFERENCES idioms(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE idiom_explanations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    idiom_id    BIGINT UNSIGNED NOT NULL,
    lang_code   CHAR(5)      NOT NULL,
    body        MEDIUMTEXT   NOT NULL,
    source      ENUM('import','manual','llm') NOT NULL DEFAULT 'import',
    is_reviewed TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expl (idiom_id, lang_code, source),
    CONSTRAINT fk_ex_idiom FOREIGN KEY (idiom_id) REFERENCES idioms(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE examples (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    idiom_id        BIGINT UNSIGNED NOT NULL,
    lang_code       CHAR(5)      NOT NULL DEFAULT 'da',
    sentence        TEXT         NOT NULL,
    sentence_masked TEXT         NULL,      -- term replaced by ______
    source          ENUM('telegram','manual','llm','corpus') NOT NULL,
    -- Nothing with is_reviewed = 0 is EVER shown to a user. Enforce in the
    -- query, not the template.
    is_reviewed     TINYINT(1)   NOT NULL DEFAULT 0,
    reviewed_at     DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_usable (idiom_id, is_reviewed),
    CONSTRAINT fk_exm_idiom FOREIGN KEY (idiom_id) REFERENCES idioms(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE example_translations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    example_id  BIGINT UNSIGNED NOT NULL,
    lang_code   CHAR(5)    NOT NULL,
    text        TEXT       NOT NULL,
    source      ENUM('manual','llm') NOT NULL DEFAULT 'llm',
    is_reviewed TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_extr (example_id, lang_code),
    CONSTRAINT fk_extr_ex FOREIGN KEY (example_id) REFERENCES examples(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE tags (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug     VARCHAR(64) COLLATE utf8mb4_0900_as_cs NOT NULL,
    kind     ENUM('topic','register','grammar') NOT NULL DEFAULT 'topic',
    label_ru VARCHAR(64) NULL,
    label_en VARCHAR(64) NULL,
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE idiom_tags (
    idiom_id BIGINT UNSIGNED NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    weight   DECIMAL(4,3) NOT NULL DEFAULT 1.000,
    source   ENUM('rule','manual','llm') NOT NULL DEFAULT 'rule',
    PRIMARY KEY (idiom_id, tag_id),
    KEY ix_tag (tag_id),
    CONSTRAINT fk_it_idiom FOREIGN KEY (idiom_id) REFERENCES idioms(id) ON DELETE CASCADE,
    CONSTRAINT fk_it_tag   FOREIGN KEY (tag_id)   REFERENCES tags(id)   ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- "these mean the same thing" -> never oppose each other in a quiz
CREATE TABLE synonym_groups (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    note VARCHAR(255) NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE idiom_synonyms (
    group_id INT UNSIGNED NOT NULL,
    idiom_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, idiom_id),
    KEY ix_syn_idiom (idiom_id),
    CONSTRAINT fk_syn_group FOREIGN KEY (group_id) REFERENCES synonym_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_syn_idiom FOREIGN KEY (idiom_id) REFERENCES idioms(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Learned "this distractor was also correct" blocks, fed by user reports.
CREATE TABLE distractor_blocks (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    correct_tr_id BIGINT UNSIGNED NOT NULL,
    blocked_tr_id BIGINT UNSIGNED NOT NULL,
    reason        ENUM('synonym','reported','manual','auto_similarity') NOT NULL,
    report_count  INT      NOT NULL DEFAULT 1,
    is_active     TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_block (correct_tr_id, blocked_tr_id),
    KEY ix_block_correct (correct_tr_id, is_active)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE users (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email            VARCHAR(190) NULL,
    password_hash    VARCHAR(255) NULL,
    display_name     VARCHAR(64)  NULL,
    ui_lang          CHAR(5)      NOT NULL DEFAULT 'ru',
    target_lang      CHAR(5)      NOT NULL DEFAULT 'ru',
    telegram_user_id BIGINT       NULL,
    role             ENUM('user','admin') NOT NULL DEFAULT 'user',
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at     DATETIME     NULL,
    UNIQUE KEY uq_email (email),
    UNIQUE KEY uq_tg (telegram_user_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE user_idiom_progress (
    user_id          BIGINT UNSIGNED NOT NULL,
    idiom_id         BIGINT UNSIGNED NOT NULL,
    ease             DECIMAL(4,2) NOT NULL DEFAULT 2.50,   -- SM-2
    interval_days    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    repetitions      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lapses           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    due_at           DATETIME NULL,
    last_result      TINYINT  NULL,
    last_answered_at DATETIME NULL,
    PRIMARY KEY (user_id, idiom_id),
    KEY ix_due (user_id, due_at),
    CONSTRAINT fk_prog_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_prog_idiom FOREIGN KEY (idiom_id) REFERENCES idioms(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE quiz_sessions (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id      CHAR(26) COLLATE utf8mb4_0900_as_cs NOT NULL,   -- ULID (Telegram callback_data is 64 bytes)
    user_id        BIGINT UNSIGNED NULL,
    anon_key       CHAR(32) COLLATE utf8mb4_0900_as_cs NULL,
    channel        ENUM('web','telegram','api') NOT NULL DEFAULT 'web',
    lang_code      CHAR(5)  NOT NULL DEFAULT 'ru',
    direction      ENUM('da_to_tr','tr_to_da') NOT NULL DEFAULT 'da_to_tr',
    question_count TINYINT UNSIGNED NOT NULL DEFAULT 10,
    answered_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    correct_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    status         ENUM('active','finished','abandoned') NOT NULL DEFAULT 'active',
    started_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at    DATETIME NULL,
    duration_ms    INT UNSIGNED NULL,
    UNIQUE KEY uq_public (public_id),
    KEY ix_user (user_id, started_at),
    KEY ix_anon (anon_key, started_at),
    KEY ix_cleanup (status, started_at),
    CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE quiz_questions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id    BIGINT UNSIGNED NOT NULL,
    position      TINYINT UNSIGNED NOT NULL,
    idiom_id      BIGINT UNSIGNED NOT NULL,
    correct_tr_id BIGINT UNSIGNED NOT NULL,
    example_id    BIGINT UNSIGNED NULL,
    options       JSON     NOT NULL,          -- [{i, tr_id, text}] presentation order
    -- SERVER-ONLY until the answer is POSTed. Never serialize into a question response.
    correct_index TINYINT UNSIGNED NOT NULL,
    chosen_index  TINYINT UNSIGNED NULL,
    is_correct    TINYINT(1) NULL,
    response_ms   INT UNSIGNED NULL,
    asked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at   DATETIME NULL,
    reported      TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_pos (session_id, position),
    KEY ix_idiom (idiom_id),
    KEY ix_tr (correct_tr_id),
    CONSTRAINT fk_q_sess FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
