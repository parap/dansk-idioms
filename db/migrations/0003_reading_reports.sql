-- A learner's objection to an item.
--
-- Reading options are written by hand, so an automatic remedy is impossible: a wrong
-- option can only be fixed by editing it. Two independent reports therefore take the item
-- out of new rounds and put it in front of a person, rather than correcting anything.
-- This is the opposite of distractor_blocks, whose options are generated and can simply
-- stop being offered.

CREATE TABLE reading_reports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id         BIGINT UNSIGNED NOT NULL,
    session_item_id BIGINT UNSIGNED NULL,
    user_id         BIGINT UNSIGNED NULL,
    anon_key        CHAR(32) COLLATE utf8mb4_0900_as_cs NULL,
    reason          ENUM('also_correct','no_correct','unclear','typo','other') NOT NULL DEFAULT 'also_correct',
    note            VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- One voice per reader per item, or a single frustrated learner buries a sound
    -- question by clicking twice.
    --
    -- The identity is folded into one non-null column on purpose. A unique key over
    -- (user_id, anon_key) enforces nothing for a guest, because MySQL ignores NULLs in a
    -- unique index -- the same property the schema relies on elsewhere to allow exactly
    -- one primary translation, working against us here.
    --
    -- VIRTUAL, not STORED: InnoDB refuses a foreign key on any column a STORED generated
    -- column derives from, and this one derives from user_id.
    reporter        VARCHAR(64) COLLATE utf8mb4_0900_as_cs
                    AS (COALESCE(CONCAT('u:', user_id), CONCAT('a:', anon_key), 'anonymous')) VIRTUAL,
    UNIQUE KEY uq_reporter (item_id, reporter),
    KEY ix_item (item_id, created_at),
    CONSTRAINT fk_rrep_item FOREIGN KEY (item_id) REFERENCES reading_items(id) ON DELETE CASCADE,
    -- Closing an account takes its reports with it: the row would otherwise keep a
    -- verdict nobody can be asked about.
    CONSTRAINT fk_rrep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
