-- Enhancement to characters table for AI-driven character management
-- Adds fields for dialogue personality mode and better AI integration

-- Add speech patterns and dialogue voice fields
ALTER TABLE characters
ADD COLUMN speech_patterns TEXT COMMENT 'How the character speaks, common phrases, dialect' AFTER personality,
ADD COLUMN voice_description TEXT COMMENT 'Tone, style, vocabulary level for dialogue generation' AFTER speech_patterns,
ADD COLUMN dialogue_examples LONGTEXT COMMENT 'Sample dialogue snippets in character voice' AFTER voice_description;

-- Add field to track if character was AI-generated
ALTER TABLE characters
ADD COLUMN ai_generated BOOLEAN DEFAULT FALSE AFTER notes,
ADD COLUMN ai_metadata JSON COMMENT 'Stores AI context, mentions, generation params' AFTER ai_generated;

-- Add index for faster name searches
ALTER TABLE characters
ADD INDEX idx_name (name);

-- Create character_images table for multiple generated images per character
CREATE TABLE IF NOT EXISTS character_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    character_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    prompt TEXT,
    generation_params JSON,
    is_primary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
    INDEX idx_character_id (character_id),
    INDEX idx_primary (is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create character_dialogue_history table for chat-with-character sessions
CREATE TABLE IF NOT EXISTS character_dialogues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    character_id INT NOT NULL,
    book_id INT NOT NULL,
    user_id INT NOT NULL,
    user_message TEXT NOT NULL,
    character_response TEXT NOT NULL,
    context TEXT COMMENT 'Scene or situation context for the dialogue',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_character_id (character_id),
    INDEX idx_book_id (book_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
