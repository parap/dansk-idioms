-- The writing check is offered to signed-in admins only, so a review always has an account
-- and the browser key a guest would be counted by has nothing to hold.

ALTER TABLE writing_checks
    DROP INDEX ix_wc_anon,
    DROP COLUMN anon_key,
    DROP INDEX ix_wc_created;
