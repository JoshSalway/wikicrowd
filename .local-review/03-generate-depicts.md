# 3. GenerateDepictsQuestions

**Branch:** `local/generate-depicts-queue-safety`
**File:** `app/Jobs/GenerateDepictsQuestions.php`
**Commit:** `d498136`
**Stat:** +18
**Priority:** **MEDIUM** — already well-configured; filling in three missing base recommendations

## What the job does

The recursive workhorse. Given a Commons category, traverses it, generates "does this image depict X?" questions, dispatches sub-jobs for subcategories. Uses Laravel's `Batchable` trait for batch coordination.

## What this branch adds

1. `$tries = 5` — retry budget for transient SPARQL / Commons API failures
2. `$backoff = [10, 30, 60, 300]` — four delays for the four inter-attempt transitions
3. `failed(Throwable)` — log terminal failure with category + depictItemId context

That's it. This job already had `$timeout = 300` and `uniqueFor() = 3600`, so the two biggest blog recs are already in place.

## Impact if NOT applied

**Medium.** The job is already the best-configured of the four remaining. `$timeout` and `uniqueFor` together prevent both the hang-forever scenario and the lock-leak-forever scenario.

What the missing three cost:

- **No `$tries`:** one transient SPARQL or Commons API failure during a category traversal = one failed job. That category's questions don't get generated. Sub-jobs in the batch may still succeed, so the batch as a whole partially fails.
- **No `$backoff`:** if you added `$tries` without `$backoff`, 5 retries fire in under a second, which on a rate-limited SPARQL endpoint makes things worse.
- **No `failed()`:** terminal failure is logged by Laravel's generic `failed_jobs` table only. No per-job context to help an operator identify WHICH category traversal died.

**User-visible symptom:** certain categories silently have fewer questions than they should because the generation job failed once on a transient and never retried. Low-severity, distributed, hard to notice.

## Is this a noob mistake?

No. This is addshore filling in the blog's base recommendations on an already-carefully-thought-through job. The `$timeout` and `uniqueFor()` values were chosen deliberately (uniqueFor comment says "longer than individual job timeout"), showing the developer did think about these interactions.

The three missing properties are in the "blog's checklist" category, not the "fixed a bug" category.

## PR Preflight

| Check | Status |
|---|---|
| PHP syntax (`php -l`) | PASS |
| Zero em/en dashes | PASS |
| Diff is minimal (one insertion block + failed method) | PASS |
| Additions respect the file's unusual 4-space indentation style | PASS (matches surrounding code) |
| `$maxExceptions` deliberately skipped, documented | PASS |
| Doesn't touch the Batchable behaviour | PASS |
| Handles the recursion-depth use case: if a recursive sub-job fails, the parent's batch `catch()` already runs | PASS (no behavioural change there) |
| Framework-level test | Partial — `failed()` integration verified on Laravel 12.56.0 via the wikicrowd-failed-method.php test |

**Residual risk:** very low. Three property/method additions to an existing, well-tested job.

## Commit message

```
Set queue-safety defaults on GenerateDepictsQuestions

This job already had $timeout = 300 and uniqueFor() = 3600. Adding
the three missing base queue-safety defaults per the blog:

- $tries = 5: retry budget for transient SPARQL / Commons API failures
- $backoff = [10, 30, 60, 300]: 4 delays for 4 inter-attempt transitions
- failed(): log terminal failures with the job's category + depictItemId
  context so operators can identify which category-traversal job died

$maxExceptions omitted per the same reasoning as AddDepicts (Toolforge,
not Vapor). Batchable already handles partial-batch failures via the
catch() callback at the dispatch level.
```

## Full diff (against `main`)

```diff
diff --git a/app/Jobs/GenerateDepictsQuestions.php b/app/Jobs/GenerateDepictsQuestions.php
@@
         public $timeout = 300;

+        public int $tries = 5;
+
+        public array $backoff = [10, 30, 60, 300];
+
         const DEPICTS_PROPERTY = 'P180';

@@ public function uniqueFor(): int
         {
             return 3600; // 1 hour - longer than individual job timeout
         }

+        /**
+         * Handle a permanently failed job (after $tries is exhausted).
+         */
+        public function failed(\Throwable $exception)
+        {
+            \Log::error("GenerateDepictsQuestions permanently failed", [
+                'category' => $this->category,
+                'depictItemId' => $this->depictItemId,
+                'depictName' => $this->depictName,
+                'recursionDepth' => $this->recursionDepth,
+                'exception' => $exception->getMessage(),
+            ]);
+        }
```
