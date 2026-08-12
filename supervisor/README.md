# Supervisor configs

One file per queue. Supervisor programs address **queues**, not job classes, so
`media.caption` gets a single file even though two jobs share it.

| File | Queue | Job(s) | `--timeout` |
|------|-------|--------|-------------|
| `media-info.conf` | `media.info` | `InfoJob` | 120 |
| `media-caption.conf` | `media.caption` | `CaptionJob`, `YoutubeCaptionJob` | 120 |
| `media-youtube-data-caption.conf` | `media.youtube-data-caption` | `YoutubeDataCaptionJob` | 120 |
| `media-summary.conf` | `media.summary` | `SummaryJob` | 300 |
| `rss-sync.conf` | `rss.sync` | `SyncJob` | 300 |
| `videotranscriber-start.conf` | `videotranscriber.start` | `VideoTranscriberStartJob` | 120 |
| `videotranscriber-fetch.conf` | `videotranscriber.fetch` | `VideoTranscriberFetchJob` | 120 |
| `videotranscriber-smart-summary.conf` | `videotranscriber.smart-summary` | `VideoTranscriberSmartSummaryJob` | 300 |

## The one rule that must hold

```
--timeout  <  DB_QUEUE_RETRY_AFTER  <  stopwaitsecs
```

**`DB_QUEUE_RETRY_AFTER` is global, not per-queue.** It lives in `.env` and
feeds `config/queue.php` → `connections.database.retry_after` (default **90**),
so a single value has to sit above the *largest* `--timeout` used by any file
here. The largest is currently **300**, so:

```
DB_QUEUE_RETRY_AFTER=360
```

Each side of that inequality breaks differently:

- **`--timeout` ≥ `retry_after`** — the queue decides a still-running job is
  stuck and hands it to a second worker. Two workers then run the same job at
  once: duplicate external API calls, and whichever finishes last overwrites
  the other's result.
- **`stopwaitsecs` ≤ `--timeout`** — every deploy or restart SIGKILLs whatever
  is mid-flight, because supervisor stops waiting before the job's own budget
  is up.

Raising any `--timeout` above 300 means raising `DB_QUEUE_RETRY_AFTER` and that
file's `stopwaitsecs` in the same change.

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
- **`--max-time=3600`** rotates each worker hourly instead of waiting for it to
  hit the memory ceiling. Note `Worker::stop()` does not drain in-flight
  coroutines, so this can still cut a job short once an hour — rare, but real.
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
