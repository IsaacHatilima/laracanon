---
name: laravel-jobs
description: "Use when adding or reviewing Laravel queued jobs, listeners, or background work such as exports, reports, webhooks, imports, and notifications, or when a request is slow because it does work the user doesn't need to wait for."
---

# Jobs

Applies to: `app/Jobs/**`, `app/Listeners/**`, `config/queue.php`.

## Rules

- Queue slow or external work that the response doesn't depend on: exports, reports, imports, outbound webhooks, third-party API calls, bulk notifications.
- A job is a transport like a controller. Its `handle()` calls an injected action, which owns the queries, writes, and transaction (see `actions`). A queued listener handles one event and follows the same rules.
- Configure queueing with class attributes from `Illuminate\Queue\Attributes`, never the matching properties: `#[Queue]`, `#[Connection]`, `#[Tries]`, `#[Backoff]`, `#[Timeout]`, `#[MaxExceptions]`, `#[FailOnTimeout]`, `#[UniqueFor]`, `#[DeleteWhenMissingModels]`. Queued listeners, mailables, and notifications read `#[Queue]`, `#[Tries]`, `#[Backoff]`, and `#[Timeout]` the same way.
- Bound every job with `#[Tries]` or `retryUntil()`, and with `#[Timeout]`. Add `#[Backoff]` for external services, and implement `failed()` to record or alert on final failure.
- Dispatch after commit when the job depends on a write: implement `ShouldQueueAfterCommit`, or dispatch from `DB::afterCommit()` (see `actions`).
- Pass models or IDs, not large payloads. Queued models are re-fetched when the job runs; add `#[DeleteWhenMissingModels]` when a deleted record should cancel the job.
- Make the work idempotent: a retry after a partial failure must not duplicate a charge, email, or row. The action checks, under a lock, for the state the job produces, or keys the external call with an idempotency key.
- When two copies running at once would conflict, implement `ShouldBeUnique` with `uniqueId()` and `#[UniqueFor]`, or use the `WithoutOverlapping` middleware. Use the `RateLimited` middleware with a named limiter for rate-limited APIs.
- Implement `ShouldBeEncrypted` when the payload carries personal or secret data.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Commits\DetectCommitLanguageAction;
use App\Models\Commit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

#[Backoff([60, 600])]
#[DeleteWhenMissingModels]
#[Queue('translations')]
#[Timeout(120)]
#[Tries(3)]
#[UniqueFor(3600)]
final class DetectCommitLanguageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Commit $commit) {}

    public function uniqueId(): string
    {
        return $this->commit->id;
    }

    public function handle(DetectCommitLanguageAction $detectLanguage): void
    {
        $detectLanguage->handle($this->commit);
    }
}
```

The job only delegates. `DetectCommitLanguageAction` returns early unless the commit is still approved or pending, so a stale or retried job changes nothing.

## Verify

- The dispatching action's test asserts the push with `Queue::fake()` and `Queue::assertPushed()`.
- A separate test runs the job's `handle()` with real actions and faked external services. A queue fake proves the dispatch, not the execution.

Adapted in part from [laravel-security](https://github.com/affaan-m/everything-claude-code/blob/main/skills/laravel-security/SKILL.md) and [laravel-patterns](https://github.com/affaan-m/everything-claude-code/blob/main/skills/laravel-patterns/SKILL.md) in everything-claude-code. The example is adapted from [dev-null.sh's `DetectCommitLanguage` job](https://github.com/dev-null-sh/dev-null.sh/blob/production/app/Jobs/DetectCommitLanguage.php).
