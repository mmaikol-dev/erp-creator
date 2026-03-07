# Import Pattern Safety Rules (Strict)

Before adding or changing imports, always inspect the target module and match its export style exactly.

## Required process
1) Read the source file first (`read_file`) to confirm exports.
2) If module uses `export default`, import with default syntax.
3) If module uses named exports, import with `{ NamedExport }`.
4) Do not guess exports from component/file name.
5) After edits, verify imports by re-reading changed files.
6) Keep imports aligned with existing shadcn component locations under `@/components/ui/*`.
7) Do not introduce alternate toast/modal packages if project already has shadcn/sonner wiring.

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
  - `href=\"/records/create\"`
- Invalid unsafe usage:
  - `href={route('records.create')}` (unless project confirms global route function is wired)

## Toast / Modal / Badge import rules
1) Toast notifications:
   - Use `import { toast } from 'sonner';` for success/error feedback.
   - Ensure toaster host is mounted once at app root if required by project setup.
2) Modal/dialogs:
   - Use `Dialog` or `AlertDialog` from `@/components/ui/dialog` or `@/components/ui/alert-dialog`.
3) Status labels:
   - Use `import { Badge } from '@/components/ui/badge';`
4) Loading indicator:
   - Use `import { Spinner } from '@/components/ui/spinner';` for CRUD action loading states.
5) Page-level loading placeholders:
   - Use `import { Skeleton } from '@/components/ui/skeleton';` for initial/loading UI sections.

## Common safe imports
- `import { toast } from 'sonner';`
- `import { Badge } from '@/components/ui/badge';`
- `import { Spinner } from '@/components/ui/spinner';`
- `import { Skeleton } from '@/components/ui/skeleton';`
- `import { Button } from '@/components/ui/button';`
- `import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';`
