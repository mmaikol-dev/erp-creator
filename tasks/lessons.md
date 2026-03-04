# Lessons

- If AI requests can become CPU-heavy, avoid synchronous indexing in request path.
- Always add request lifecycle logs for chat endpoints (`request/success/failed`) to debug silent failures quickly.
- Prefer fail-fast network connect timeouts for model provider calls to avoid long hangs.
