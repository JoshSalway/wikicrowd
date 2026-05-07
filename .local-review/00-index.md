# Wikicrowd queue-safety review

Four local branches adding queue-safety defaults to the remaining jobs in `app/Jobs/`, based on the queue-safety blog post. Nothing pushed, nothing shared.

## Preflight-adjusted confidence scores

Deep preflight by a subagent surfaced two issues on `ValidateQuestions` — line numbers wrong in the commit message, and the retry-budget claim overstated for HTTP timeouts. Commit amended (new SHA `ed5898c`). Other three branches passed deep preflight cleanly.

| # | Branch | Commit | Confidence | Ship order |
|---|---|---|---|---|
| 1 | `validate-questions-queue-safety` | `ed5898c` (amended) | **88%** (↑ from 72%) | 4 |
| 2 | `generate-yaml-queue-safety` | `2d35de1` | **90%** | 3 |
| 3 | `generate-depicts-queue-safety` | `d498136` | **94%** | 2 |
| 4 | `remove-unanswered-queue-safety` | `f4d3e01` | **95%** | 1 (safest) |

Ship order is safest first: smallest diffs, lowest risk, most easily reviewed. Reverse the order (highest impact first) if you care about shipping the timeout fix sooner than the DB-only hygiene.

## Is this noob code? No.

addshore is a senior Wikimedia contributor, the codebase is thoughtfully structured, and the `ShouldBeUnique` pattern is already used correctly. What's missing from these jobs is what Laravel doesn't nudge you toward:

- `make:job` stub ships with zero queue-safety properties set
- `Http::get()` accepts no timeout, raises no warning
- `uniqueFor` defaults to `0` = "never expires" (surprising)
- `ThrottlesExceptions::$retryAfterMinutes` defaults to `0`
- `WithoutOverlapping::$expiresAfter` defaults to `0`
- PHP's `file_get_contents` has no per-call timeout parameter

Framework-defaults issues, not code-quality issues. Better defaults would remove ~80% of what these branches fix.

## Per-branch summary

1. **[ValidateQuestions](01-validate-questions.md)** — adds base queue-safety properties + `Http::timeout(10)` on two Commons API calls that previously had no timeout. **Preflight surfaced:** the per-question `try/catch` in `handle()` swallows HTTP exceptions, so `$tries` does NOT retry Wikimedia failures. HTTP-timeout fix is the strong value; `$tries` is defence-in-depth against non-HTTP failure paths. Commit message amended to state this honestly.

2. **[GenerateDepictsQuestionsFromYaml](02-generate-yaml.md)** — same base properties + replaces two `@file_get_contents($url)` calls with `Http::timeout(10)->get()->throw()->body()`. Also fixes a latent bug in the original code (the early-return on `@file_get_contents` failure leaked the Cache::lock). Retry budget DOES fire on HTTP failures here because the error propagates.

3. **[GenerateDepictsQuestions](03-generate-depicts.md)** — already had `$timeout = 300` and `uniqueFor() = 3600`. Adds the three missing blog base recs: `$tries`, `$backoff`, `failed()`. Purely additive.

4. **[RemoveUnansweredQuestions](04-remove-unanswered.md)** — DB-only cleanup. Minimal additions: `$tries = 3`, `$timeout = 120`, `failed()`. No `$backoff` (DB retries are fast). Cleanest diff of the four.

## What happens if these DON'T get merged

- **#1 not merged:** ValidateQuestions stays silently slow when Commons is slow. Workers periodically pinned. Stale questions remain in the UI because cleanup pipelines are quiet-failing.
- **#2 not merged:** YAML question generation silently hangs on slow Commons, and continues to leak the lock on `file_get_contents` failure (latent existing bug that this branch also fixes). New questions stop appearing.
- **#3 not merged:** transient SPARQL failure during category traversal stops that category's question generation with no retry. Categories silently under-populated.
- **#4 not merged:** very little. DB cleanup is scheduled; a failed run is caught by the next one. Purely defensive hygiene.

## `$maxExceptions` deliberately omitted on all four

Same reasoning as AddDepicts (PR #238): would contradict the `$tries` retry budget. The blog's aggressive-zero stance targets Vapor/Lambda OOM loops; wikicrowd runs on Toolforge (Kubernetes, stable memory), so that justification doesn't apply.

## Suggested shipping order (by safety-first)

1. RemoveUnansweredQuestions (95%, DB-only, trivial)
2. GenerateDepictsQuestions (94%, purely additive)
3. GenerateDepictsQuestionsFromYaml (90%, fixes latent lock leak + adds timeout)
4. ValidateQuestions (88%, real HTTP-timeout fix + honest retry scope)

If addshore is cool on PR #238, maybe only #2 and #3 are worth submitting (they fix real bugs rather than just adding blog-checklist properties).

## How to view a branch's actual state locally

```bash
cd C:/Users/Josh/Herd/wikicrowd
git checkout local/validate-questions-queue-safety   # or any of the four
git diff main
git checkout main
```

## Delete this review folder when done

```bash
rm -rf C:/Users/Josh/Herd/wikicrowd/.local-review
```
