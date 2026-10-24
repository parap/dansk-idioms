-- The worked example (0) a Modultest cloze or insertion task opens with. It is shown answered
-- and scores nothing, so it is no item: a cloze keeps {"options": [...], "answer": i}, an
-- insertion {"part": "D", "text": "..."}, the part also standing in the bank.

ALTER TABLE reading_passages
    ADD COLUMN example JSON NULL AFTER body;
