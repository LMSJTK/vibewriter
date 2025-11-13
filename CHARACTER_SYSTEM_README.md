# Character Management System

## Overview

The AI-driven character management system automatically creates and maintains character profiles as you discuss your story with the AI assistant. Characters can be viewed, edited, and even chatted with to help develop authentic dialogue.

## ✨ Features Implemented

### 🤖 AI-Driven Character Creation
- **Automatic Detection**: When you mention a character in conversation, the AI automatically creates a character sheet
- **Progressive Enhancement**: As you discuss more details, the AI updates the character profile
- **Smart Tracking**: AI tracks character mentions and keeps profiles synchronized

### 👥 Character Management
- **Beautiful Character Cards**: Grid view with images, roles, and key details
- **Role-Based Organization**: Protagonist, Antagonist, Supporting, Minor
- **Manual Creation**: Option to manually create characters via form
- **Detailed Profiles**: Name, age, gender, physical description, personality, background, motivation, relationships

### 🎨 Character Images
- **Multiple Images Per Character**: Store different variations
- **Primary Image Selection**: Choose which image represents the character
- **Image Generation Ready**: Database structure supports AI-generated images
- **Placeholder Initials**: Beautiful letter-based placeholders until images are added

### 💬 Dialogue Features (Foundation)
- **Speech Patterns**: Store how the character speaks
- **Voice Description**: Document tone, vocabulary, dialect
- **Dialogue Examples**: Save sample quotes
- **Chat-With-Character Mode**: Infrastructure for personality-based dialogue generation

### 📊 Character Database
Enhanced schema with:
- Core info (name, role, age, gender)
- Appearance (physical_description, profile_image)
- Personality (personality, speech_patterns, voice_description)
- Story (background, motivation, arc, relationships)
- AI metadata (ai_generated flag, ai_metadata JSON)
- Dialogue history tracking
- Multiple image storage

## 🚀 How It Works

### For Writers

**Step 1: Mention a Character**
```
You: "I'm thinking Sarah is a tough detective in her 30s who hides
vulnerability behind sarcasm"
```

**Step 2: AI Creates Character**
```
AI: [Calls create_character tool]
"I've created a character sheet for Sarah! She's now in your Characters
tab. Want me to generate an image of her?"
```

**Step 3: Add More Details**
```
You: "Sarah has red hair and always wears a leather jacket. She drinks
too much coffee."
```

**Step 4: AI Updates Character**
```
AI: [Calls update_character tool]
"Updated Sarah's profile with her appearance and habits!"
```

**Step 5: View Your Characters**
- Click "👥 Characters" button in navbar
- See all your characters with their details
- Click "View Details" for full profile
- Click "💬 Chat" to enter dialogue mode (coming soon)

### For Developers

**AI Tool Flow:**
1. User mentions character name
2. AI calls `create_character` tool with available details
3. Backend creates DB record via `createCharacter()`
4. AI returns success with character_id
5. Frontend receives `characters_created` array
6. Green notification appears: "👥 Characters Updated"
7. User clicks "View Characters" to see new character

**Database Structure:**
```sql
characters
├── Core: id, book_id, name, role, age, gender
├── Appearance: physical_description, profile_image
├── Personality: personality, speech_patterns, voice_description
├── Story: background, motivation, arc, relationships
├── Dialogue: dialogue_examples, notes
└── AI: ai_generated, ai_metadata (JSON)

character_images
├── character_id, file_path, prompt
├── generation_params (JSON)
└── is_primary

character_dialogues
├── character_id, book_id, user_id
├── user_message, character_response
└── context
```

## 📁 Files Created/Modified

### Backend
- **`database/migrations/001_enhance_characters.sql`** - Database schema
- **`includes/characters.php`** - Character CRUD functions (396 lines)
- **`api/ai_chat.php`** - Added 5 character tools + handlers (300+ lines added)
- **`api/create_character.php`** - Manual character creation endpoint

### Frontend
- **`characters.php`** - Character listing page with grid view
- **`assets/css/characters.css`** - Character styles (300+ lines)
- **`assets/js/characters.js`** - Character JavaScript (200+ lines)
- **`assets/js/book.js`** - Added character notifications

## 🎯 AI Tools

### Available to AI Assistant

1. **`read_characters`**
   - Lists all characters in the book
   - Shows basic info for context

2. **`read_character`**
   - Gets full details of specific character
   - Includes all fields for reference

3. **`create_character`**
   - Creates new character when first mentioned
   - Required: name
   - Optional: role, physical_description, personality, speech_patterns, background, motivation, age, gender

4. **`update_character`**
   - Updates existing character with new details
   - Required: character_id
   - Optional: any character field

5. **`delete_character`**
   - Removes character from book
   - Use carefully, asks confirmation

### AI Behavior

The AI is instructed to:
- **Proactively create** characters when first mentioned
- **Immediately update** when new details are discussed
- **Check first** with `read_characters` before creating duplicates
- **Use tools immediately** (not describe what it will do)

Example instruction to AI:
```
When user mentions a character:
✗ WRONG: "I'll create a character sheet for Sarah"
✓ RIGHT: [Calls create_character] "Created character sheet for Sarah!"
```

## 🔧 Setup Instructions

### 1. Apply Database Migration

```bash
mysql -u root -p vibewriter < database/migrations/001_enhance_characters.sql
```

Or via phpMyAdmin:
1. Select vibewriter database
2. Go to SQL tab
3. Paste contents of `001_enhance_characters.sql`
4. Execute

### 2. Verify Tables Created

```sql
SHOW TABLES LIKE 'character%';
```

Should show:
- `characters` (enhanced)
- `character_images` (new)
- `character_dialogues` (new)

### 3. Test Character Creation

1. Open a book in VibeWriter
2. Click "💬 AI Assistant"
3. Say: "I'm thinking about a character named John who is a brave knight"
4. Watch for green notification: "👥 Characters Updated"
5. Click "View Characters" or navigate to Characters page
6. Should see John's character card

### 4. Verify Tool Calling

Check PHP error logs for:
```
AI Tool Called: create_character with input: {"name":"John",...}
Claude API stop_reason: tool_use
```

## 🎨 Image Generation (Future)

The system is ready for image generation:

```php
// Example: Generate character image
function generateCharacterImage($characterId, $character) {
    $prompt = "Portrait of {$character['name']}: {$character['physical_description']}";

    // Call DALL-E / Stable Diffusion / Midjourney API
    $imageUrl = callImageAPI($prompt);

    // Save to character_images
    addCharacterImage($characterId, $imageUrl, $prompt, $params, true);
}
```

**To implement:**
1. Choose image service (DALL-E, Stable Diffusion, Midjourney)
2. Add API configuration to `config/config.php`
3. Create `generate_character_image` AI tool
4. Add image generation endpoint
5. Update UI with "Generate Image" button

## 💬 Chat-With-Character Mode (Future)

Infrastructure is in place for dialogue mode:

**How it will work:**
1. User clicks "💬 Chat" on character card
2. AI context switches to character personality
3. AI responds IN CHARACTER based on personality, speech patterns, voice
4. Dialogue saved to `character_dialogues` table
5. Good lines can be exported to binder

**To implement:**
1. Create `api/character_chat.php` endpoint
2. Build character context from profile
3. System prompt: "You are [Character]. Respond as they would..."
4. Save dialogue history
5. Add "Save to Scene" button for good quotes

## 📊 Statistics & Analytics

Get character stats:
```php
$stats = getCharacterStats($characterId);
// Returns: dialogue_count, image_count, mention_count
```

Track:
- Number of AI conversations mentioning character
- Dialogue exchanges in character mode
- Generated images
- Profile completeness

## 🧪 Testing Checklist

- [ ] Apply database migration successfully
- [ ] Character table has new fields (speech_patterns, ai_generated, etc.)
- [ ] Mention character in AI chat → character created
- [ ] Add details → character updated
- [ ] Green notification appears
- [ ] Click "View Characters" → see character card
- [ ] Click "View Details" → see full profile
- [ ] Manually create character via form
- [ ] Role badges show correct colors
- [ ] Image placeholder shows initial
- [ ] AI-generated badge appears on AI-created characters

## 🚦 Current Status

### ✅ Completed
- Database schema with migrations
- Backend CRUD functions (all working)
- AI tool definitions (5 tools)
- AI tool handlers (all implemented)
- Character listing page (beautiful grid)
- Character cards with images
- Character creation form
- Character notifications (green banners)
- AI context instructions (comprehensive)
- Integration with book page

### 🚧 Planned (Phase 2)
- Character detail view page
- Character image generation integration
- Chat-with-character endpoint
- Dialogue history viewer
- Character relationship graph
- Export character profiles
- Import characters from templates

## 💡 Usage Tips

**For Best Results:**
1. Describe characters naturally in conversation
2. Be specific about physical traits, personality, voice
3. Mention character relationships and motivations
4. Reference characters by name consistently
5. Ask AI to "tell me more about [character]" to add details

**Example Conversation:**
```
You: I need a protagonist for my mystery novel

AI: What kind of protagonist are you envisioning?

You: A middle-aged private detective named Marcus. He's cynical but
has a good heart. Dresses in rumpled suits and always has a cigarette.

AI: [Creates character automatically]
I've created a character sheet for Marcus! He sounds perfect for a noir
mystery. Want me to help develop his backstory?

You: Yes! Maybe he was a cop who got disillusioned

AI: [Updates character with background]
Added Marcus's backstory. What case brings him into your story?
```

## 🤝 Contributing

To extend the character system:

1. **Add new fields**: Update migration + `includes/characters.php`
2. **Add AI tool**: Define in `callClaudeAPI()` → add handler → add to context
3. **Add UI feature**: Update `characters.php` and `characters.css`
4. **Add endpoint**: Create new file in `api/` folder

## 📝 Notes

- Characters are scoped to individual books
- Character deletion cascades (removes images, dialogues)
- AI-generated flag helps track which came from AI vs manual
- JSON metadata field allows flexible future expansion
- System uses same notification pattern as binder items
- Compatible with existing VibeWriter authentication system

---

**Built with ❤️ for VibeWriter**
AI-Powered Book Writing Tool
