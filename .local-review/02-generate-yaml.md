# 2. GenerateDepictsQuestionsFromYaml

**Branch:** `local/generate-yaml-queue-safety`
**File:** `app/Jobs/GenerateDepictsQuestionsFromYaml.php`
**Commit:** `2d35de1`
**Stat:** +31 / -8
**Priority:** **HIGH** — two silent-failure modes fixed in one branch

## What the job does

Downloads a YAML config from Commons (`User:Addshore/wikicrowd.yaml`), parses it, dispatches a batch of `GenerateDepictsQuestions` sub-jobs based on the config. It's the entry-point for question generation.

## What this branch adds

1. `$tries = 5`, `$backoff = [10, 30, 60, 300]`, `$timeout = 300` — the base three
2. `failed(Throwable)` — log terminal failure with `depictItemId` and `yamlUrl`
3. Both `@file_get_contents($url)` calls replaced with `Http::timeout(10)->get()->throw()->body()`. Added `use Illuminate\Support\Facades\Http;`

## Impact if NOT applied

**Two distinct silent-failure modes:**

**Mode 1: hang on slow Commons.** The two `@file_get_contents` calls use PHP's default socket timeout (configured in `php.ini`, frequently 60+ seconds or unlimited). If Commons is slow, the job hangs. No timeout at the job level means the worker is pinned indefinitely. New questions stop generating. No alert.

**Mode 2: silent success on HTTP error.** `@file_get_contents($url)` returns `false` on network failure, which the code checks for. But on HTTP 500? It returns the error page as the YAML content. `Yaml::parse()` then tries to parse HTML as YAML, fails, the check `if (!is_object($parsed) || !isset($parsed->questions))` catches it and logs "YAML structure invalid." But this is a different code path from "download failed" and an operator reading the logs would see different errors for the same underlying cause.

With `Http::timeout(10)->throw()`, both cases converge: a clean `ConnectionException` or `RequestException` that feeds the existing `$lock->release()` + `throw` pattern.

**User-visible symptom over time:** question-generation batches silently drop entire categories when Commons hiccups. Users report "no questions available for X" and the cause is invisible in the job logs.

## Is this a noob mistake?

No. `@file_get_contents($url)` is a pattern from pre-modern PHP that still works and doesn't warn. The `@` suppresses `file_get_contents failed to open stream` warnings so the `=== false` check can handle the error cleanly. The gap is that PHP's `file_get_contents` has no per-call timeout parameter (you'd need a stream context). Laravel's `Http` facade is the modern replacement, but nothing in the language or framework prompts a migration.

## PR Preflight

| Check | Status |
|---|---|
| PHP syntax (`php -l`) | PASS (pre-existing deprecation warning on line 38 for implicit nullable `$yamlUrl` parameter — unrelated to this change) |
| Zero em/en dashes | PASS |
| Diff is minimal, focused | PASS |
| `Http` facade imported correctly | PASS |
| Error path preserved: existing `try / finally / $lock->release()` still runs on `Http` exception | PASS |
| `->throw()` behaviour matches needed semantics (HTTP 4xx/5xx → exception → release lock → retry) | PASS |
| `->body()` returns string (matches previous `$yamlContent` type) | PASS |
| No unrelated changes | PASS |
| `$maxExceptions` deliberately skipped, documented | PASS |
| Framework-level test | Partial — `Http::timeout()` behaviour is documented; specific fail-and-release flow not run against this job |

**Residual risk:** low. The success path is unchanged: `Http::timeout(10)->get()->throw()->body()` returns the same string as `file_get_contents` would on success. The failure paths now throw earlier and cleaner.

**Pre-existing issue noted (not my fix):** line 38 has `string $yamlUrl = null` which triggers a PHP 8.4 deprecation for implicit nullable parameters. Should be `?string $yamlUrl = null`. Worth a separate follow-up PR.

## Commit message

```
Set queue-safety defaults and Http::timeout on GenerateDepictsQuestionsFromYaml

- $tries = 5: retry budget for transient Wikimedia failures
- $backoff = [10, 30, 60, 300]: 4 delays for 4 inter-attempt transitions
- $timeout = 300: matches sibling GenerateDepictsQuestions
- failed(): surface terminal failure separately from per-attempt logging
- Replace two @file_get_contents calls with Http::timeout(10)->get()->throw()->body().
  The @-suppressed file_get_contents calls had no timeout, so a slow Commons
  response could pin the worker indefinitely. Http::timeout bounds them to
  10 seconds and raises an exception on 4xx/5xx which feeds the existing
  release-lock-and-rethrow error path.

$maxExceptions omitted per the same reasoning as AddDepicts (Toolforge, not
Vapor).
```

## Full diff (against `main`)

```diff
diff --git a/app/Jobs/GenerateDepictsQuestionsFromYaml.php b/app/Jobs/GenerateDepictsQuestionsFromYaml.php
@@
 use Illuminate\Support\Facades\Bus;
 use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\Http;
 use Symfony\Component\Yaml\Yaml;

 class GenerateDepictsQuestionsFromYaml implements ShouldQueue, ShouldBeUnique
 {
     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

+    public int $tries = 5;
+
+    public array $backoff = [10, 30, 60, 300];
+
+    public int $timeout = 300;
+
     private $depictItemId;

@@ public function handle()
     $defaultYamlUrl = 'https://commons.wikimedia.org/wiki/User:Addshore/wikicrowd.yaml?action=raw';
-    $yamlContent = @file_get_contents($defaultYamlUrl);
-    if ($yamlContent === false) {
-        \Log::error("Failed to download YAML from $defaultYamlUrl");
-        return;
-    }
+    try {
+        $yamlContent = Http::timeout(10)->get($defaultYamlUrl)->throw()->body();
+    } catch (\Throwable $e) {
+        \Log::error("Failed to download YAML from $defaultYamlUrl: " . $e->getMessage());
+        $lock->release();
+        throw $e;
+    }

@@ if ($this->yamlUrl !== null && $this->yamlUrl !== '') {
-        $overrideContent = @file_get_contents($this->yamlUrl);
-        if ($overrideContent === false) {
-            \Log::error("Failed to download override YAML from {$this->yamlUrl}");
-            return;
-        }
+        try {
+            $overrideContent = Http::timeout(10)->get($this->yamlUrl)->throw()->body();
+        } catch (\Throwable $e) {
+            \Log::error("Failed to download override YAML from {$this->yamlUrl}: " . $e->getMessage());
+            $lock->release();
+            throw $e;
+        }

+    public function failed(\Throwable $exception)
+    {
+        \Log::error("GenerateDepictsQuestionsFromYaml permanently failed", [
+            'depictItemId' => $this->depictItemId,
+            'yamlUrl' => $this->yamlUrl,
+            'exception' => $exception->getMessage(),
+        ]);
+    }
```
