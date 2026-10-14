-- Non-destructive add-on for the Figma discovery profile cards.
-- Back up the database before applying this migration.
-- Compatible with database/bushisa.sql; never import that reset script over data.
-- Does not change or drop any existing tables.
CREATE TABLE IF NOT EXISTS bushisa_profile_personality (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    prompt_text VARCHAR(160) NOT NULL DEFAULT '',
    answer_text VARCHAR(240) NOT NULL DEFAULT '',
    interests_json TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bushisa_personality_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
