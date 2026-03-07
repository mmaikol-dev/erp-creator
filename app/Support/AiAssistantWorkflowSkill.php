<?php

namespace App\Support;

class AiAssistantWorkflowSkill
{
    public static function text(): string
    {
        return <<<TEXT
Workflow Orchestration:
1) Plan Node Default: enter plan mode for non-trivial tasks (3+ steps or architectural decisions). If something goes sideways, stop and re-plan.
2) Subagent Strategy: offload research/exploration to focused subagents where available.
3) Self-Improvement Loop: after corrections, capture lessons and prevention rules.
4) Verification Before Done: prove it works with tests/logs/behavior checks before marking complete.
5) Demand Elegance: choose elegant solutions for non-trivial changes, avoid hacks.
6) Autonomous Bug Fixing: identify and fix root cause directly.

Task Management:
- Plan first with checkable items.
- Track progress and explain changes.
- Document results and lessons.
- Keep final responses concise and clean; avoid decorative emojis and promotional filler text.
- For web-derived answers, include a clear Sources section with direct URLs.

Frontend UI Rules (Universal):
- Theme baseline:
  - Use the default shadcn UI visual system already present in this project.
  - Do not invent a custom theme or override global design tokens unless the user explicitly requests it.
- Typography system:
  - Use a consistent heading/body scale across new pages.
  - Prefer existing project font tokens/classes; do not introduce one-off font utilities unless already established.
  - Keep heading hierarchy semantic (`h1`/`h2`) and visually distinct.
- Color tokens:
  - Use semantic project tokens/utilities (surface, muted, border, accent, destructive, success, warning); avoid hard-coded hex colors in page JSX.
  - Keep contrast accessible; avoid low-contrast text on muted backgrounds.
- Spacing rhythm:
  - Use consistent spacing steps (`gap-*`, `space-y-*`, `p-*`) and align with nearby page patterns.
  - Favor simple, repeatable layout spacing over ad-hoc pixel values.
- Mobile-first:
  - Start with base (mobile) layout; enhance at `sm/md/lg`.
  - Prevent horizontal overflow on small screens.
  - Tables/lists must remain usable on narrow viewports (stacking, wrapping, or horizontal scroll container).
- Accessibility:
  - Every interactive control needs a visible label or `aria-label`.
  - Inputs must be associated with labels and error/help text.
  - Ensure keyboard focus visibility and logical tab order.
  - Use meaningful empty/loading/error states (not blank containers).
- Reuse-first:
  - Prefer existing components in `resources/js/components` and `resources/js/components/ui` before creating new primitives.
  - Match existing page/layout conventions before introducing new structure.
- Feedback patterns:
  - For CRUD actions, use toast notifications for both success and error outcomes.
  - Use project modal components (`Dialog` / `AlertDialog`) for confirm flows (delete, destructive actions, multi-step confirmations).
  - Use `Badge` for concise status labels (active/inactive, draft/published, role/state metadata).
  - For page-level loading states, use `Skeleton` placeholders from `@/components/ui/skeleton`.
  - Always show loading icons (spinner/loader) while create/update/delete actions are processing.
  - Disable submit/action buttons while processing to prevent duplicate writes.

CRUD Delivery Contract:
- If the user asks to create/build/generate a page or resource, treat it as a full CRUD scaffold unless they explicitly request partial scope.
- Deliver all required pieces: routes, controller methods, requests/validation, model (if missing), migration (if missing), and Inertia/React pages.
- Ensure route names and imports are valid and wired to real controller methods.
- Add a clickable sidebar navigation entry to the generated page so users can access it from the app sidebar.
- Sidebar safety rule: preserve existing sidebar component structure and only append a new nav item in the existing `mainNavItems` array.
- Import safety rule: always read target files and match export style exactly (default vs named); never guess.
- Route helper safety rule: do not assume global `route(...)` exists in React pages; read existing pages first and follow project route helper pattern (e.g. imports from `@/routes/...`) or use explicit URLs.
- `alert-error` import rule: use default import `import AlertError from '@/components/alert-error';`.
- Eloquent table rule: always verify model table names against migrations; for acronym/camel-case models (e.g. `ApiTokenRecord`) set explicit `\$table` to avoid wrong inferred names.
- Validation/FK rule: ensure `exists:` validation rules and FK constraints reference the exact migration table names.
- Use tools to inspect existing patterns before editing so generated code matches project conventions.
- Before finishing, verify no required CRUD artifact is missing and provide a changed-files summary.
- For React/TypeScript edits, run a TypeScript check (`npm run types`) and fix reported errors before final response.

Core Principles:
- Simplicity first.
- No laziness; solve root cause.
- Minimal-impact changes.
TEXT;
    }
}
