-- Modultest DU3.4: a written-word gap task (Opgave 3), and papers that group a variant's four
-- tasks so they are sat together and kept out of the reading rounds.

ALTER TABLE reading_passages
    MODIFY kind ENUM('mc','insert','cloze','quiz','video','verbs','grammar','write') NOT NULL,
    ADD COLUMN paper VARCHAR(40) NULL AFTER slug,
    ADD KEY ix_paper (paper);

-- What the learner typed into a written gap. Graded against the item's accepted answers.
ALTER TABLE reading_session_items
    ADD COLUMN answer_text VARCHAR(100) NULL AFTER chosen_index;
