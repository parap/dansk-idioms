-- The integration suite builds and drops a scratch schema from the real migrations.
-- Granting on the name rather than creating it keeps the database itself disposable.
GRANT ALL PRIVILEGES ON `dansk_test`.* TO 'dansk'@'%';
FLUSH PRIVILEGES;
