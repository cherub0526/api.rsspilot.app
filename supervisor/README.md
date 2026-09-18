# Supervisor configs

One file per queue. Supervisor programs address **queues**, not job classes, so
`media.caption` gets a single file even though two jobs share it.

| File | Queue | Job(s) | `--timeout` |
|------|-------|--------|-------------|
| `media-info.conf` | `media.info` | `InfoJob` | 120 |
| `media-caption.conf` | `media.caption` | `CaptionJob`, `YoutubeCaptionJob` | 120 |
| `media-youtube-data-caption.conf` | `media.youtube-data-caption` | `YoutubeDataCaptionJob` | 120 |
| `media-summary.conf` | `media.summary` | `SummaryJob` | 300 |
| `media-summary-translation.conf` | `media.summary-translation` | `SummaryTranslationJob` | 120 |
| `media-notify.conf` | `media.notify` | `DailyDigestJob` | 120 |
| `videotranscriber-start.conf` | `videotranscriber.start` | `VideoTranscriberStartJob` | 120 |
| `videotranscriber-fetch.conf` | `videotranscriber.fetch` | `VideoTranscriberFetchJob` | 120 |
| `videotranscriber-smart-summary.conf` | `videotranscriber.smart-summary` | `VideoTranscriberSmartSummaryJob` | 300 |
| `videotranscriber-archive.conf` | `videotranscriber.archive` | `VideoTranscriberArchiveJob` | 300 |

## The two rules that must hold

They are **independent**, and mixing them into one chain is what makes this
confusing. One is global, one is per-program:

```
DB_QUEUE_RETRY_AFTER  >  the LARGEST --timeout in this directory   (global)
stopwaitsecs          >  this program's own --timeout              (per file)
```

**`DB_QUEUE_RETRY_AFTER` is global, not per-queue.** It feeds `config/queue.php`
→ `connections.database.retry_after` (default **90**), so a single value has to
sit above every `--timeout` used here. The largest is **300**, so:

```
DB_QUEUE_RETRY_AFTER=360
```

`retry_after` does **not** need to be smaller than `stopwaitsecs`. With 360
global, the `--timeout=120` programs keep `stopwaitsecs=180` and are perfectly
correct: 120 < 180 satisfies their own rule, and 360 > 120 satisfies the global
one. An earlier version of this file wrote the two as a single chain
(`--timeout < DB_QUEUE_RETRY_AFTER < stopwaitsecs`), which only holds when every
program shares the same `--timeout` — it does not, so the chain reads as broken
even when the configuration is right.

Each rule breaks differently:

- **`--timeout` ≥ `retry_after`** — the queue decides a still-running job is
  stuck and hands it to a second worker. Two workers then run the same job at
  once: duplicate external API calls, and whichever finishes last overwrites
  the other's result. Or, when `$tries` runs out first, the job dies with
  `MaxAttemptsExceededException` while the external service was perfectly fine.
  Measured 2026-09-18 on staging: `retry_after` was still the default 90 against
  `--timeout=120`, and 26 jobs failed exactly this way.
- **`stopwaitsecs` ≤ `--timeout`** — every deploy or restart SIGKILLs whatever
  is mid-flight, because supervisor stops waiting before the job's own budget
  is up.

Raising any `--timeout` means checking both: the global value (if this becomes
the largest) and that file's own `stopwaitsecs`.

**`DB_QUEUE_RETRY_AFTER` lives nowhere near the flags** — not in these files,
not in `.railway/railway.ts`. On Railway it is a service variable and must also
be listed in `ENV_KEYS`, or the next `railway config apply` deletes it and the
value silently falls back to 90.

## Why `--timeout` matters more than it looks

In Hypervel a job exceeding `--timeout` does **not** just kill that job — the
worker sends `SIGKILL` to *itself* (`Worker::monitorTimeoutJobs()` →
`kill()` → `posix_kill(getmypid(), SIGKILL)`). The job dies mid-execution, so a
`ShouldBeUnique` job never releases its lock and every dispatch for that media
is silently dropped until `uniqueFor` expires. `autorestart=true` brings the
process straight back, so supervisor looks healthy the whole time.

See `docs/lore/transcription/pitfalls.md` for the full write-up.

## Deliberate choices

- **No `--quiet`.** It empties `stdout_logfile`, which is the only place a
  self-inflicted SIGKILL leaves a trace.
- **No `--daemon`.** Deprecated in `WorkCommand` and does nothing.
- **No `--max-time`.** It used to be `3600`, to rotate each worker hourly rather
  than wait for the memory ceiling. On hypervel/framework v0.3.17 it does not
  rotate anything — it turns the worker into a zombie. `Worker::stop()` only
  dispatches an event and returns (no `exit`), while the Swoole timer registered
  by `monitorTimeoutJobs()` is never cleared (`monitorId` is only ever written,
  never `Timer::clear`ed). The daemon loop returns, the event loop still holds
  that timer, so **the process never exits**: supervisor sees it RUNNING and
  `autorestart` never fires, while the worker stops reserving jobs for good.
  Measured on Railway 2026-09-18: both workers had been "Online" but idle for
  ~29 hours, 26 jobs untouched with `attempts = 0`, zero CPU over a 20s sample.
  Removed 2026-09-18. The same path is still reachable through `--memory`, just
  far less often; the real fix is upstream in `stop()`.
- **`--memory=256`** over the 128 default, which is low for a long-running
  Swoole process.

The `--timeout` values are starting points sized to what each job does, except
`videotranscriber.smart-summary`, which is measured. Tune them against real
runs rather than treating them as settled.

## Installing

These are not read from the repo — Forge keeps its own copies. Paste a file's
contents into a new Forge daemon, or copy it onto the box and reload:

```bash
sudo cp supervisor/<name>.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
```

Program names here are descriptive; Forge generates its own numeric ones
(`worker-1008263`). Keep the queue name and the parameter relationship —
the program name itself does not matter.
