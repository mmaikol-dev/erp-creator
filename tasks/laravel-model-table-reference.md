# Laravel Model/Table Consistency Rules (Strict)

When creating or editing Eloquent models and migrations, table names must be explicitly verified.

## Required process
1) Read the migration(s) first and copy the exact table names from `Schema::create(...)`.
2) If a model name has acronyms or unusual capitalization (examples: `ApiSessionRecord`, `APIKey`, `OAuthToken`), set an explicit `$table` on the model.
3) Ensure all request validation `exists:` rules and foreign-key constraints use the exact same table name.
4) Re-read changed model + migration + request files before finalizing.
5) Use consistent singular model + plural table naming unless an existing legacy table dictates otherwise.
6) If migration table naming diverges from Laravel inference, model must declare explicit `$table`.

## Example from this project
- Migration table: `api_session_records`
- Correct model:
  - `protected $table = 'api_session_records';`
- Wrong implicit guess:
  - `api_session_records`

## FK and validation alignment examples
- Migration FK:
  - `$table->foreignId('customer_id')->constrained('customers');`
- Request validation:
  - `'customer_id' => ['required', 'exists:customers,id']`
- Wrong mismatch example:
  - FK constrained to `customer_profiles` but validation uses `exists:customers,id`.

## Acronym model exceptions (must be explicit)
- `APIKey` -> expected table may not match intent; set:
  - `protected $table = 'api_keys';`
- `OAuthToken` -> set:
  - `protected $table = 'oauth_tokens';`
- `SSOProvider` -> set:
  - `protected $table = 'sso_providers';`

## Final verification checklist
- Model `$table` matches migration table exactly.
- Every FK `constrained(...)` table matches request `exists:` table.
- Pivot/relationship table names match actual migrations.
- No guessed table names remain in validation rules.
