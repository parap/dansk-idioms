-- The indfødsretsprøve is passed or failed, not graded.
--
-- karakter stays NULL for these sittings: the reading exam's scale converts a score into
-- a mark on a curve the ministry recalibrates, and this exam does neither. It states a
-- number of correct answers and a second requirement inside the values block, and the
-- answer that matters is which side of that line the paper fell.
--
-- A round that is not the whole paper -- one sat without the current-affairs block --
-- leaves this NULL, because the stated mark belongs to the paper as it was sat.

ALTER TABLE reading_sessions
    ADD COLUMN verdict ENUM('bestaaet','ikke_bestaaet') NULL AFTER karakter;
