-- Verb drills: questions about the forms of Danish verbs, with nothing to read or watch.
--
-- Same shape as a film's questions -- numbered questions with lettered options, graded
-- from rows written before any of it is served -- so it reuses the reading tables.

ALTER TABLE reading_passages
    MODIFY kind ENUM('mc','insert','cloze','quiz','video','verbs') NOT NULL;
