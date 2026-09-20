-- Knowledge papers: the indfødsretsprøve, which is questions and nothing else.
--
-- It reuses the reading module's tables because it is the same shape -- a paper of
-- numbered questions, each with lettered options, graded from rows written before any of
-- it is served -- and differs only in having no text to read, two options where the
-- values questions answer Ja or Nej, and a pass mark of its own.

ALTER TABLE reading_passages
    MODIFY kind ENUM('mc','insert','cloze','quiz') NOT NULL,
    -- A quiz paper has no passage. NULL says that; an empty string would claim there is
    -- a text and that it happens to be blank.
    MODIFY body MEDIUMTEXT NULL,
    -- Correct answers needed to pass, as the paper's own answer sheet states it: 32 of 40
    -- before the values block existed, 36 of 45 since. It is a property of the paper, not
    -- of a scale shared with the reading exam, which is recalibrated for every session.
    ADD COLUMN pass_points  TINYINT UNSIGNED NULL AFTER word_count,
    -- And of those, the minimum that must come from the values block. The requirement is
    -- a conjunction: 36 correct overall is not a pass with fewer than 4 of the last 5.
    ADD COLUMN vaerdier_min TINYINT UNSIGNED NULL AFTER pass_points;

ALTER TABLE reading_items
    -- Which block of the paper asked this question. The current-affairs block ages out --
    -- a 2021 question about a 2021 minister trains nobody in 2026 -- so a round must be
    -- able to leave it out, and that is only possible if the block is stored.
    ADD COLUMN section ENUM('laeremateriale','aktuelle','vaerdier') NULL AFTER position;
