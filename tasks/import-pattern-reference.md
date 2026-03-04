# Import Pattern Safety Rules (Strict)

Before adding or changing imports, always inspect the target module and match its export style exactly.

## Required process
1) Read the source file first (`read_file`) to confirm exports.
2) If module uses `export default`, import with default syntax.
3) If module uses named exports, import with `{ NamedExport }`.
4) Do not guess exports from component/file name.
5) After edits, verify imports by re-reading changed files.

## Project-specific example
- `resources/js/components/alert-error.tsx` uses `export default function AlertError(...)`.
- Correct usage:
  - `import AlertError from '@/components/alert-error';`
- Incorrect usage:
  - `import { AlertError } from '@/components/alert-error';`

## Route Usage Safety (Strict)
1) Do not assume global Ziggy `route(...)` is available in page components.
2) Read existing pages and match route usage pattern in this project.
3) Prefer typed route helpers from `@/routes` or `@/routes/<module>`.
4) If no helper is used in nearby pages, use explicit URL strings (`/resource`, `/resource/{id}`) consistently.
5) Before finalizing, verify there are no unresolved global `route(...)` calls in changed files.

Examples:
- Valid helper usage:
  - `import { login } from '@/routes';`
  - `href={login()}`
- Valid explicit URL usage:
  - `href=\"/support-tickets/create\"`
- Invalid unsafe usage:
  - `href={route('support-tickets.create')}` (unless project confirms global route function is wired)
