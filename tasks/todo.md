# TODO

## Plan
- [x] Add `deep` assistant mode (glm planning -> coder execution).
- [x] Add UI mode switch (`Fast` / `Deep`) and pass mode to backend.
- [x] Extend validation rules to accept `deep` mode.
- [x] Add orchestration workflow skill text to assistant system prompt.
- [x] Add/update tests for deep-mode orchestration.
- [x] Verify with lint/types/tests.

## Review
- Implemented two-stage deep orchestration with model fallback on each stage.
- Added workflow skill instructions into prompt for stronger execution discipline.
- Kept fast mode behavior unchanged for normal low-latency requests.
