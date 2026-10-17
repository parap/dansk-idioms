-- Grammar drills: questions on one rule each, explained once answered. Same shape as the
-- verb drills, so they reuse the reading tables.

ALTER TABLE reading_passages
    MODIFY kind ENUM('mc','insert','cloze','quiz','video','verbs','grammar') NOT NULL;
