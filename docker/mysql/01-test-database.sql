-- The integration suite builds and drops a scratch schema from the real migrations.
-- Granting on the name rather than creating it keeps the database itself disposable.
GRANT ALL PRIVILEGES ON `dansk_test`.* TO 'dansk'@'%';
-- The fault-injection runner's own scratch schema, so it never drops the developer's.
GRANT ALL PRIVILEGES ON `dansk_mutants`.* TO 'dansk'@'%';
FLUSH PRIVILEGES;
