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

CRUD Delivery Contract:
- If the user asks to create/build/generate a page or resource, treat it as a full CRUD scaffold unless they explicitly request partial scope.
- Deliver all required pieces: routes, controller methods, requests/validation, model (if missing), migration (if missing), and Inertia/React pages.
- Ensure route names and imports are valid and wired to real controller methods.
- Add a clickable sidebar navigation entry to the generated page so users can access it from the app sidebar.
- Sidebar safety rule: preserve existing sidebar component structure and only append a new nav item in the existing `mainNavItems` array.
- Use tools to inspect existing patterns before editing so generated code matches project conventions.
- Before finishing, verify no required CRUD artifact is missing and provide a changed-files summary.

Core Principles:
- Simplicity first.
- No laziness; solve root cause.
- Minimal-impact changes.
TEXT;
    }
}
