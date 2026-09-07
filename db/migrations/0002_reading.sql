-- Reading comprehension: Proeve i Dansk 3, Laeseforstaaelse 2.
--
-- In the exam each delproeve has exactly one text and exactly one task type, so a passage
-- and a task are 1:1. That collapses three apparent data models into one shape: an item
-- belonging to a passage, offering N options, one of them correct, worth P points, at
-- position K. The kind discriminator carries the difference.
--
--   mc     3 questions, 3 options each, 2 points  -- options belong to the item
--   insert 5 gaps, a shared bank of 7 lettered parts including 2 decoys, 2 points
--   cloze  8 gaps, 4 options each, 1 point        -- options belong to the item
--
-- Collation: MySQL 8's default utf8mb4_0900_ai_ci is accent-insensitive and treats
-- 'a' = 'aa' = the Danish letters. A UNIQUE slug under it refuses the second of two
-- passages whose slugs differ only by a Danish character, which is silent data loss on
-- exactly the alphabet this project exists for. Identity columns are therefore explicitly
-- utf8mb4_0900_as_cs. Prose columns keep the default so that searching stays forgiving.

CREATE TABLE reading_passages (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug         VARCHAR(96) COLLATE utf8mb4_0900_as_cs NOT NULL,
    kind         ENUM('mc','insert','cloze') NOT NULL,
    title        VARCHAR(255) NOT NULL,
    -- Gap markers {{1}}..{{n}} for insert and cloze; mc passages carry none. The marker
    -- count must equal the item count or the paper cannot be rendered.
    body         MEDIUMTEXT NOT NULL,
    lang_code    CHAR(5) NOT NULL DEFAULT 'da',
    word_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    source       ENUM('manual','llm') NOT NULL DEFAULT 'manual',
    is_reviewed  TINYINT(1) NOT NULL DEFAULT 0,
    -- Nothing unpublished is EVER served to a learner. Enforce in the query.
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_passage_slug (slug),
    KEY ix_pub (is_published, kind)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE reading_items (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    passage_id        BIGINT UNSIGNED NOT NULL,
    position          TINYINT UNSIGNED NOT NULL,
    points            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- The question, for mc. A gap has no prompt: the passage body is the question.
    prompt            TEXT NULL,
    correct_option_id BIGINT UNSIGNED NULL,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    is_flagged        TINYINT(1) NOT NULL DEFAULT 0,
    report_count      INT UNSIGNED NOT NULL DEFAULT 0,
    times_asked       INT UNSIGNED NOT NULL DEFAULT 0,
    times_correct     INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_item_pos (passage_id, position),
    -- An option answers at most one item. For the insertion task this is the whole
    -- "each lettered part is usable once" rule, held by the schema rather than by
    -- whoever wrote the paper.
    UNIQUE KEY uq_item_correct (correct_option_id),
    KEY ix_servable (passage_id, is_active, is_flagged),
    CONSTRAINT fk_ritem_passage FOREIGN KEY (passage_id) REFERENCES reading_passages(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE reading_options (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    passage_id BIGINT UNSIGNED NOT NULL,
    -- NULL means the option belongs to the passage-wide bank rather than to one gap,
    -- which is what lets the insertion task share seven parts across five items.
    item_id    BIGINT UNSIGNED NULL,
    label      CHAR(1) COLLATE utf8mb4_0900_as_cs NOT NULL,
    text       VARCHAR(500) NOT NULL,
    sort       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    KEY ix_opt_item (item_id),
    KEY ix_opt_bank (passage_id, item_id),
    CONSTRAINT fk_ropt_passage FOREIGN KEY (passage_id) REFERENCES reading_passages(id) ON DELETE CASCADE,
    CONSTRAINT fk_ropt_item    FOREIGN KEY (item_id)    REFERENCES reading_items(id)    ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

ALTER TABLE reading_items
    ADD CONSTRAINT fk_ritem_correct FOREIGN KEY (correct_option_id)
        REFERENCES reading_options(id) ON DELETE SET NULL;

-- A paper is materialised into reading_session_items BEFORE any of it is served, and the
-- correct index lives only there. Grading reads that row; the request body contributes
-- nothing but a chosen index. This mirrors quiz_questions, for the same reason.

CREATE TABLE reading_sessions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_id     CHAR(26) COLLATE utf8mb4_0900_as_cs NOT NULL,
    user_id       BIGINT UNSIGNED NULL,
    anon_key      CHAR(32) COLLATE utf8mb4_0900_as_cs NULL,
    mode          ENUM('drill','exam') NOT NULL DEFAULT 'drill',
    scale_id      INT UNSIGNED NULL,
    status        ENUM('active','submitted','abandoned') NOT NULL DEFAULT 'active',
    points_max    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    points_scored SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    karakter      VARCHAR(4) NULL,
    -- The clock is the server's. duration_s is stamped at start and compared against
    -- TIMESTAMPDIFF at submit; a client-supplied elapsed time is never trusted.
    duration_s    INT UNSIGNED NULL,
    started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at  DATETIME NULL,
    elapsed_s     INT UNSIGNED NULL,
    is_late       TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_rsess_public (public_id),
    KEY ix_user (user_id, started_at),
    KEY ix_anon (anon_key, started_at),
    CONSTRAINT fk_rsess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE reading_session_items (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id    BIGINT UNSIGNED NOT NULL,
    position      SMALLINT UNSIGNED NOT NULL,
    item_id       BIGINT UNSIGNED NOT NULL,
    passage_id    BIGINT UNSIGNED NOT NULL,
    options       JSON NOT NULL,
    -- SERVER-ONLY until the paper is graded. Never serialize into a question response.
    correct_index TINYINT UNSIGNED NOT NULL,
    points        TINYINT UNSIGNED NOT NULL,
    chosen_index  TINYINT UNSIGNED NULL,
    is_correct    TINYINT(1) NULL,
    points_scored TINYINT UNSIGNED NULL,
    response_ms   INT UNSIGNED NULL,
    answered_at   DATETIME NULL,
    reported      TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_rpos (session_id, position),
    KEY ix_item (item_id),
    CONSTRAINT fk_rsi_sess FOREIGN KEY (session_id) REFERENCES reading_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- Scheduling is per item, but selection serves whole passages: a learner cannot answer
-- gap 5 without the text, so the passage is what a round is built around.
CREATE TABLE user_reading_progress (
    user_id          BIGINT UNSIGNED NOT NULL,
    item_id          BIGINT UNSIGNED NOT NULL,
    ease             DECIMAL(4,2) NOT NULL DEFAULT 2.50,
    interval_days    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    repetitions      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lapses           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    due_at           DATETIME NULL,
    last_result      TINYINT NULL,
    last_answered_at DATETIME NULL,
    PRIMARY KEY (user_id, item_id),
    KEY ix_due (user_id, due_at),
    CONSTRAINT fk_rprog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rprog_item FOREIGN KEY (item_id) REFERENCES reading_items(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- The real exam recalibrates its conversion every session: the maximum itself moved from
-- 37 points in 2019 and 2021 to 39 in 2023 and 2024, and every band edge moved with it.
-- There is therefore no single official table to hard-code. Ours is data, versioned by
-- code, and a revision is an INSERT plus a flip of is_active rather than an edit to PHP.
CREATE TABLE reading_grade_scales (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(64) COLLATE utf8mb4_0900_as_cs NOT NULL,
    label      VARCHAR(128) NOT NULL,
    max_points SMALLINT UNSIGNED NOT NULL,
    -- 1 or NULL, NEVER 0: MySQL ignores NULLs in a unique index, so this is how
    -- "exactly one active scale" is enforced rather than merely intended.
    is_active  TINYINT(1) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scale_code   (code),
    UNIQUE KEY uq_scale_active (is_active)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE reading_grade_bands (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scale_id   INT UNSIGNED NOT NULL,
    min_points SMALLINT UNSIGNED NOT NULL,
    max_points SMALLINT UNSIGNED NOT NULL,
    karakter   VARCHAR(4) NOT NULL,
    UNIQUE KEY uq_band (scale_id, min_points),
    CONSTRAINT fk_band_scale FOREIGN KEY (scale_id) REFERENCES reading_grade_scales(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

INSERT INTO reading_grade_scales (code, label, max_points, is_active)
VALUES ('pd3_reading2_24_v1', 'PD3 Laeseforstaaelse 2, 24 point', 24, 1);

-- Band edges scaled from the archive's own 39-point proportions onto this paper's 24.
-- The pass mark lands at 12 of 24, matching 20 of 39 in the exam.
INSERT INTO reading_grade_bands (scale_id, min_points, max_points, karakter)
SELECT s.id, v.lo, v.hi, v.k
FROM reading_grade_scales s
JOIN (            SELECT  0 AS lo,  5 AS hi, '-3' AS k
        UNION ALL SELECT  6,       11,       '00'
        UNION ALL SELECT 12,       13,       '02'
        UNION ALL SELECT 14,       16,       '4'
        UNION ALL SELECT 17,       19,       '7'
        UNION ALL SELECT 20,       22,       '10'
        UNION ALL SELECT 23,       24,       '12') v
WHERE s.code = 'pd3_reading2_24_v1';
