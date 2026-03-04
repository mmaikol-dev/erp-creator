# Laravel Model/Table Consistency Rules (Strict)

When creating or editing Eloquent models and migrations, table names must be explicitly verified.

## Required process
1) Read the migration(s) first and copy the exact table names from `Schema::create(...)`.
2) If a model name has acronyms or unusual capitalization (examples: `WhatsAppConversation`, `APIKey`, `OAuthToken`), set an explicit `$table` on the model.
3) Ensure all request validation `exists:` rules and foreign-key constraints use the exact same table name.
4) Re-read changed model + migration + request files before finalizing.

## Example from this project
- Migration table: `whatsapp_conversations`
- Correct model:
  - `protected $table = 'whatsapp_conversations';`
- Wrong implicit guess:
  - `whats_app_conversations`
