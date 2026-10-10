-- What a question shows once it is answered: for a verb, the whole verb with its meaning.
-- Display only, never graded, so it can be filled in on a set that has already been sat.

ALTER TABLE reading_items
    ADD COLUMN note VARCHAR(500) NULL AFTER prompt;
