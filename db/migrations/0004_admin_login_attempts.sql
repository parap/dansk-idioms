-- Failed admin sign-ins, so guessing the shared password costs time.
--
-- The client is stored as a hash rather than an address: it is only ever compared for
-- equality, and a log of who tried to sign in is not something this application needs to
-- keep in readable form.
--
-- Two rows are consulted for every attempt. The per-client row is the useful limit. The
-- row keyed 'global' is the backstop: the client is derived from a proxy header, which a
-- caller can change at will, so per-client alone would be bypassed by rotating it.

CREATE TABLE admin_login_attempts (
    client       CHAR(64) COLLATE utf8mb4_0900_as_cs NOT NULL PRIMARY KEY,
    failures     INT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_stale (last_seen_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
