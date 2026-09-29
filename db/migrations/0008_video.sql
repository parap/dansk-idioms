-- Listening tasks: a film on YouTube and questions about it, with nothing to read.
--
-- Same shape as a knowledge paper -- numbered questions with lettered options, graded
-- from rows written before any of it is served -- so it reuses the reading tables.

ALTER TABLE reading_passages
    MODIFY kind ENUM('mc','insert','cloze','quiz','video') NOT NULL,
    -- The film a video task is about. The id alone: the page builds the embed address.
    ADD COLUMN youtube_id CHAR(11) COLLATE utf8mb4_0900_as_cs NULL AFTER body;
