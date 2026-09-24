-- What the bot was sent, held between the message and the decision about it.
--
-- A message whose idiom boundaries are not knowable is not published at all: the bot
-- shows the split it would make and waits. That wait needs somewhere to keep the text,
-- because nothing else can hand it back later -- the Bot API has no history, and a
-- proposal rendered into a message has already lost the entities that say which words
-- are bold, which is a quarter of what this corpus splits on.
--
-- So the text is kept verbatim, with its entities beside it, and the rendered list is
-- only a view of it.
--
-- The token is a ULID because it travels in callback_data, which Telegram caps at 64
-- bytes -- the same reason quiz_sessions carries one.
--
-- state leaves 'offered' by a conditional UPDATE, so a second press finds nothing to
-- claim. Publishing is not undoable and a doubled press would mean doubled posts.

CREATE TABLE communicator_drafts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token         CHAR(26) COLLATE utf8mb4_0900_as_cs NOT NULL,
    owner_chat_id VARCHAR(64)  NOT NULL,
    text_plain    MEDIUMTEXT   NOT NULL,
    entities      JSON         NULL,
    kind          ENUM('text','video') NOT NULL DEFAULT 'text',
    state         ENUM('offered','taken','expired') NOT NULL DEFAULT 'offered',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at    DATETIME     NULL,
    UNIQUE KEY uq_token (token),
    KEY ix_state (state, created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
