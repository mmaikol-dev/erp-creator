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
