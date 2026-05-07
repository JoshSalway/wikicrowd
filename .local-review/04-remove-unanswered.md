# 4. RemoveUnansweredQuestions

**Branch:** `local/remove-unanswered-queue-safety`
**File:** `app/Jobs/RemoveUnansweredQuestions.php`
**Commit:** `f4d3e01`
**Stat:** +15
**Priority:** **LOW** — defensive hygiene, no urgent bug

## What the job does

Given a question group name, deletes every question in that group that has no answer and no edit. Pure database cleanup. No external I/O.

## What this branch adds

1. `$tries = 3` — light retry budget for transient DB failures
2. `$timeout = 120` — cap runtime in case the delete query spirals
3. `failed(Throwable)` — log the group name on terminal failure

Deliberately NOT added: `$backoff`, `$maxExceptions`.

## Impact if NOT applied

**Very low.** This is a DB-only cleanup job with no external calls. The risk profile is completely different from the other three:

- No HTTP call to hang on
- No rate-limited upstream to respect
- No Commons API weirdness
- Just a `DELETE` query with `->doesntHave('answer')->doesntHave('edit')`

If the DB has a transient blip (deadlock, connection reset), the current code fails the job once. Next scheduled run runs the same cleanup and catches up. Questions don't get corrupted, just lingering slightly longer until the next successful run.

With `$tries = 3`, the retry lets the first transient heal on its own. With `failed()`, an operator can see which group name was being cleaned up if the job permanently dies.

**User-visible symptom if not applied:** essentially none. Occasional duplicate-dispatch or skipped-cleanup at worst. The data stays consistent.

## Why smaller additions than the other three

This is the "don't over-engineer a DB-only job" branch. Five reasons to go minimal:

- No external I/O → no hang risk → no need for aggressive `$timeout`
- DB retries are near-instant → no need for `$backoff`
- No `ShouldBeUnique` → no `uniqueFor()`
- Job is simple (one method, one query) → minimal surface for surprise
- The blog itself distinguishes base recs from conditional ones; for a DB-only job, the conditional ones (HTTP timeout, `uniqueFor`, `expireAfter`) don't apply

## Is this a noob mistake?

No. Not setting `$tries` or `failed()` on a DB-only cleanup job is a reasonable default: if a DB job fails once, the next scheduled run handles it. The additions here are defensive, not corrective.

## PR Preflight

| Check | Status |
|---|---|
| PHP syntax (`php -l`) | PASS |
| Zero em/en dashes | PASS |
| Diff is minimal (3 properties + 1 method) | PASS |
| Commit message explains what was added AND what was skipped and why | PASS |
| No unrelated changes | PASS |
| Doesn't fight the simple shape of the existing code | PASS |
| Framework-level test | Partial — `failed()` integration verified on Laravel 12.56.0 |

**Residual risk:** negligible. This is the smallest change with the lowest blast radius.

## If you only ship 1-3 of these PRs, skip this one

It's the most skippable of the four. The other three each fix a real silent-failure scenario. This one adds defensive logging and retry tolerance to a job that already fails safely (by being a scheduled cleanup that runs again next cycle).

## Commit message

```
Set minimal queue-safety defaults on RemoveUnansweredQuestions

This is a DB-only cleanup job (no external I/O), so the surface for
runaway behaviour is small. Minimal additions:

- $tries = 3: transient DB errors (deadlock, connection blip) retry
  cheaply. 3 not 5 because there's no upstream to wait on.
- $timeout = 120: cap runtime in case the delete query spirals (large
  question group with many unanswered rows).
- failed(): log the group name on terminal failure.

$backoff omitted: DB retries are fast and safe; 0s backoff is fine.
$maxExceptions omitted: same reasoning as elsewhere (Toolforge, not
Vapor).
```

## Full diff (against `main`)

```diff
diff --git a/app/Jobs/RemoveUnansweredQuestions.php b/app/Jobs/RemoveUnansweredQuestions.php
@@
 class RemoveUnansweredQuestions implements ShouldQueue
 {
     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

+    public int $tries = 3;
+
+    public int $timeout = 120;
+
     private $groupName;

@@ public function handle()
         $c = Question::where('question_group_id', '=', $qg->id)->doesntHave('answer')->doesntHave('edit')->delete();
         echo "Deleted $c questions\n";
     }

+    /**
+     * Handle a permanently failed job (after $tries is exhausted).
+     */
+    public function failed(\Throwable $exception)
+    {
+        \Log::error("RemoveUnansweredQuestions permanently failed", [
+            'groupName' => $this->groupName,
+            'exception' => $exception->getMessage(),
+        ]);
+    }

 }
```
