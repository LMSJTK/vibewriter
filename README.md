# VibeWriter

**AI-Powered Book Writing & Organization Tool**

VibeWriter is a comprehensive web-based application designed for authors who want to write, organize, and develop their books with the assistance of AI. Inspired by professional writing tools like Scrivener, Atticus, and Plottr, VibeWriter combines powerful organizational features with generative AI to help you bring your stories to life.

## Features

### 🎯 Core Features (Implemented)

- **Hierarchical Project Structure (Binder)** - Organize your book with folders, chapters, scenes, and notes in a flexible tree structure
- **Auto-Saving Editor** - Write without worry with automatic content saving
- **AI Chat Assistant** - Get help with plot development, character creation, scene writing, and more using Claude or GPT models
- **Multiple Book Projects** - Manage multiple books simultaneously
- **User Authentication** - Secure login and registration system
- **Word Count Tracking** - Monitor your progress with real-time word counts
- **Status Tracking** - Mark items as "To Do", "In Progress", "Done", or "Revised"
- **Snapshots/Version Control** - Create snapshots of your work before major revisions
- **Synopsis Support** - Write brief summaries for each chapter/scene
- **Planning Workspace** - Toggle between an editor, visual corkboard, and sortable outliner with drag-and-drop reordering that stays in sync with the binder

### 🚀 Upcoming Features

- **Character Sheets** - Detailed character profiles with AI-generated descriptions and images
- **Split-Screen Reference Mode** - View research materials while writing
- **Metadata Tracking** - Custom fields like POV, Setting, Subplot for advanced organization
- **Export Functionality** - Export to PDF, EPUB, DOCX, and other formats
- **Location/Worldbuilding Manager** - Organize settings and world details
- **Plot Thread Tracking** - Manage main plots and subplots
- **Research Material Integration** - Store PDFs, images, and web links within your project
- **AI Image Generation** - Generate character portraits and scene visuals
- **Collaboration Features** - Share and co-author books

## Technology Stack

- **Backend**: PHP 7.4+ with PDO (no frameworks - pure vanilla PHP)
- **Database**: MySQL/MariaDB
- **Frontend**: HTML5, CSS3, Vanilla JavaScript
- **AI Integration**: Anthropic Claude or OpenAI GPT chat models
- **Architecture**: RESTful API design with JSON responses

## Installation

### Requirements

- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.2+
- Web server (Apache, Nginx, or PHP built-in server)
- Composer (optional, not currently required)

### Quick Start

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd vibewriter
   ```

2. **Set up your web server**
   - Point your document root to the `vibewriter` directory
   - Ensure `.htaccess` files are enabled (for Apache)

3. **Run the installation**
   - Navigate to `http://your-domain/install.php`
   - Enter your database credentials
   - The installer will create the database and tables automatically

4. **Configure AI (Optional but Recommended)**
  - Get an Anthropic Claude API key from https://console.anthropic.com/ or use an OpenAI API key with GPT-4.1/GPT-5.1 models
   - Edit `config/config.php` and add your API key:
     ```php
     define('AI_API_KEY', 'your-api-key-here');
     ```

5. **Register your account**
   - Navigate to `http://your-domain/register.php`
   - Create your user account
   - Start writing!

### Manual Database Setup

If you prefer to set up the database manually:

```bash
mysql -u root -p
CREATE DATABASE vibewriter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vibewriter;
SOURCE database/schema.sql;
```

Then update `config/database.php` with your credentials.

## Usage Guide

### Creating Your First Book

1. Log in to your account
2. Click "New Book" on the dashboard
3. Enter your book title, genre, and description
4. Click "Create Book"

### Writing Your Book

1. Open your book from the dashboard
2. Use the **Binder** (left sidebar) to create chapters and scenes
3. Click on any item to start writing
4. Your work auto-saves every 2 seconds

### Using the AI Assistant

1. Click "AI Assistant" in the top navigation
2. Ask questions about your book:
   - "Help me develop my protagonist's backstory"
   - "Suggest a compelling opening scene"
   - "What are some plot twists for a mystery novel?"
3. The AI has context about your book and current scene

### Organizing Your Work

- **Folders**: Group related chapters or sections
- **Chapters**: Main divisions of your book
- **Scenes**: Individual writing segments within chapters
- **Notes**: Ideas, reminders, and planning documents
- **Research**: Reference materials and background information

### Planning Workspace (Corkboard, Outliner & Notes)

1. Open a book and use the tabs above the editor to switch between **Editor**, **Corkboard**, and **Outliner** views.
2. On the **Corkboard**, drag cards or use the keyboard handle (focus the ⋮⋮ button and press arrow keys) to reorder scenes. Drops are saved instantly and reflected in the binder.
3. The **Outliner** lists the same items in a table. Click column headers to sort, edit titles or POV inline, and update status with the dropdown — changes auto-save via existing endpoints.
4. Selecting a card or row updates the binder/editor selection so your writing, planning, and metadata always stay in sync.
5. Use **Outline Notes** for a traditional nested outline. Press Tab/Shift+Tab (or the toolbar buttons) to indent/outdent bullets, and jot down freeform beats or questions that auto-save as you type.

### Version Control with Snapshots

Before making major changes:
1. Click the "Snapshot" button
2. Give it a descriptive name (optional)
3. Continue editing with confidence
4. Restore from snapshots if needed

## Project Structure

```
vibewriter/
├── api/                    # API endpoints for AJAX requests
│   ├── ai_chat.php        # AI chat integration
│   ├── create_item.php    # Create chapters/scenes
│   ├── delete_item.php    # Delete items
│   ├── save_item.php      # Auto-save content
│   └── update_item.php    # Update item properties
├── assets/
│   ├── css/               # Stylesheets
│   │   ├── auth.css       # Authentication pages
│   │   ├── book.css       # Book view styles
│   │   └── main.css       # Main application styles
│   └── js/                # JavaScript files
│       └── book.js        # Book view interactivity
├── config/                # Configuration files
│   ├── config.php         # Main configuration
│   └── database.php       # Database connection
├── database/              # Database schemas
│   └── schema.sql         # Complete database structure
├── includes/              # PHP includes and functions
│   ├── auth.php           # Authentication functions
│   ├── book_items.php     # Book item management
│   └── books.php          # Book management functions
├── uploads/               # User uploaded files (created on install)
│   ├── characters/
│   ├── covers/
│   ├── locations/
│   └── research/
├── book.php               # Main book editing interface
├── dashboard.php          # User dashboard
├── index.php              # Landing page
├── install.php            # Installation wizard
├── login.php              # Login page
├── logout.php             # Logout handler
├── register.php           # Registration page
└── README.md              # This file
```

## Database Schema

The application uses a comprehensive relational database structure:

- **users** - User accounts
- **books** - Book/project metadata
- **book_items** - Hierarchical structure (chapters, scenes, notes)
- **characters** - Character profiles
- **locations** - Settings and places
- **plot_threads** - Story arcs and subplots
- **research_items** - Reference materials
- **ai_conversations** - Chat history
- **snapshots** - Version control
- **writing_sessions** - Productivity tracking
- **generated_media** - AI-generated images
- **item_metadata** - Custom fields
- **tags** - Flexible categorization

## Security Features

- **Password Hashing**: bcrypt via PHP's `password_hash()`
- **CSRF Protection**: Token-based CSRF prevention
- **SQL Injection Protection**: PDO prepared statements
- **XSS Prevention**: HTML escaping on output
- **Session Management**: Secure session handling
- **Authentication**: Login required for all operations
- **Authorization**: Users can only access their own books

## API Endpoints

All API endpoints return JSON responses:

- `POST /api/save_item.php` - Auto-save content
- `POST /api/create_item.php` - Create new item
- `POST /api/update_item.php` - Update item properties
- `POST /api/delete_item.php` - Delete item
- `POST /api/create_snapshot.php` - Create version snapshot
- `POST /api/ai_chat.php` - AI chat interaction

## Configuration

### Database Configuration
Edit `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'vibewriter');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

### AI Configuration
Edit `config/config.php`:
```php
// AI provider configuration
define('AI_PROVIDER', 'anthropic'); // 'anthropic' or 'openai'
define('AI_API_KEY', 'your-ai-api-key');
if (AI_PROVIDER === 'openai') {
    define('AI_API_ENDPOINT', 'https://api.openai.com/v1/chat/completions');
} else {
    define('AI_API_ENDPOINT', 'https://api.anthropic.com/v1/messages');
}
define('AI_MODEL', 'claude-3-5-sonnet-20241022'); // e.g., 'gpt-5.1-chat-latest' when using OpenAI

// Optional: Image generation
define('IMAGE_API_KEY', 'your-image-api-key');
```

## Development Roadmap

### Phase 1: Core Writing Features ✅
- [x] User authentication
- [x] Book project management
- [x] Hierarchical Binder structure
- [x] Text editor with auto-save
- [x] AI chat integration
- [x] Snapshots/version control

### Phase 2: Character & World Building (In Progress)
- [ ] Character sheet templates
- [ ] AI character generation
- [ ] Location/setting manager
- [ ] World-building tools
- [ ] Character relationship mapping

### Phase 3: Visual Planning
- [x] Corkboard view with index cards
- [x] Outliner view with metadata columns
- [x] Drag-and-drop reorganization
- [ ] Timeline view
- [ ] Story structure templates

### Phase 4: Media & Assets
- [ ] AI image generation for characters
- [ ] AI scene visualization
- [ ] Research file management
- [ ] Image galleries
- [ ] Mood boards

### Phase 5: Export & Publishing
- [ ] Export to PDF
- [ ] Export to EPUB
- [ ] Export to DOCX
- [ ] Manuscript formatting
- [ ] Publishing templates

### Phase 6: Collaboration
- [ ] Multi-user editing
- [ ] Comments and feedback
- [ ] Share projects
- [ ] Editor workflow

## Contributing

This project is in active development. Contributions are welcome!

## License

[Add your license here]

## Credits

Inspired by:
- **Scrivener** - For the Binder concept and hierarchical organization
- **Atticus** - For user-friendly design principles
- **Plottr** - For visual planning approaches
- **Reedsy Studio** - For collaboration features
- **Campfire** - For world-building organization

Built with passion for writers, by writers.

## Support

For issues and questions, please create an issue in the repository.

---

**Happy Writing! 📚✨**
