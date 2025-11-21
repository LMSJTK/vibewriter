-- VibeWriter Database Schema
-- AI-Powered Book Writing and Organization Tool

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Books/Projects table
CREATE TABLE IF NOT EXISTS books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    genre VARCHAR(100),
    target_word_count INT DEFAULT 0,
    current_word_count INT DEFAULT 0,
    cover_image VARCHAR(255),
    status ENUM('planning', 'drafting', 'revising', 'completed') DEFAULT 'planning',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hierarchical structure for chapters, scenes, and notes (Binder-style)
CREATE TABLE IF NOT EXISTS book_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    parent_id INT DEFAULT NULL,
    item_type ENUM('folder', 'chapter', 'scene', 'note', 'research') NOT NULL,
    title VARCHAR(255) NOT NULL,
    synopsis TEXT,
    content LONGTEXT,
    word_count INT DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    status ENUM('to_do', 'in_progress', 'done', 'revised') DEFAULT 'to_do',
    label VARCHAR(50), -- For color coding (e.g., 'first_draft', 'revised', 'final')
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES book_items(id) ON DELETE CASCADE,
    INDEX idx_book_id (book_id),
    INDEX idx_parent_id (parent_id),
    INDEX idx_position (position),
    INDEX idx_type (item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Freeform outline notes for each book (traditional outline separate from binder items)
CREATE TABLE IF NOT EXISTS book_outline_notes (
    book_id INT PRIMARY KEY,
    outline_text LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_outline_book_id (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metadata for book items (custom fields like POV, Setting, Subplot, etc.)
CREATE TABLE IF NOT EXISTS item_metadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    meta_key VARCHAR(100) NOT NULL,
    meta_value TEXT,
    FOREIGN KEY (item_id) REFERENCES book_items(id) ON DELETE CASCADE,
    INDEX idx_item_id (item_id),
    INDEX idx_meta_key (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Characters table
CREATE TABLE IF NOT EXISTS characters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    role ENUM('protagonist', 'antagonist', 'supporting', 'minor') DEFAULT 'supporting',
    age INT,
    gender VARCHAR(50),
    physical_description TEXT,
    personality TEXT,
    background TEXT,
    motivation TEXT,
    arc TEXT,
    relationships TEXT,
    notes TEXT,
    profile_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_book_id (book_id),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Locations/Settings table
CREATE TABLE IF NOT EXISTS locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    atmosphere TEXT,
    significance TEXT,
    notes TEXT,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_book_id (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plot threads/subplots table
CREATE TABLE IF NOT EXISTS plot_threads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    thread_type ENUM('main', 'subplot', 'character_arc') DEFAULT 'subplot',
    status ENUM('open', 'resolved') DEFAULT 'open',
    color VARCHAR(7), -- Hex color for visual distinction
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_book_id (book_id),
    INDEX idx_type (thread_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Research materials table
CREATE TABLE IF NOT EXISTS research_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    item_type ENUM('note', 'link', 'file', 'image', 'pdf') NOT NULL,
    content LONGTEXT,
    file_path VARCHAR(255),
    url VARCHAR(500),
    tags TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_book_id (book_id),
    INDEX idx_type (item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Chat history table
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    response TEXT NOT NULL,
    context_type ENUM('general', 'character', 'plot', 'scene', 'worldbuilding') DEFAULT 'general',
    context_id INT, -- References the specific character, scene, etc. being discussed
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_book_id (book_id),
    INDEX idx_user_id (user_id),
    INDEX idx_context (context_type, context_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshots table (version control for scenes)
CREATE TABLE IF NOT EXISTS snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    title VARCHAR(255),
    content LONGTEXT NOT NULL,
    snapshot_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES book_items(id) ON DELETE CASCADE,
    INDEX idx_item_id (item_id),
    INDEX idx_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Writing goals and statistics
CREATE TABLE IF NOT EXISTS writing_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    words_written INT DEFAULT 0,
    time_spent INT DEFAULT 0, -- In minutes
    notes TEXT,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_book_user (book_id, user_id),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generated media (AI-generated images, videos, etc.)
CREATE TABLE IF NOT EXISTS generated_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    entity_type ENUM('character', 'location', 'scene', 'cover') NOT NULL,
    entity_id INT, -- References character, location, or scene
    media_type ENUM('image', 'video') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    prompt TEXT,
    generation_params JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_book_id (book_id),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags for flexible categorization
CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    color VARCHAR(7), -- Hex color
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many relationship for tagging various entities
CREATE TABLE IF NOT EXISTS entity_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('book_item', 'character', 'location', 'research') NOT NULL,
    entity_id INT NOT NULL,
    tag_id INT NOT NULL,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_tag_id (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
