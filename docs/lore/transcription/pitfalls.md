---
area: transcription
kind: pitfalls
---

# transcription — Pitfalls

## ShouldBeUnique 的殘留鎖會讓 dispatch 靜默消失

`code:` `app/Jobs/Media/VideoTranscriberStartJob.php` · `code:` `app/Jobs/Media/VideoTranscriberFetchJob.php` · `updated:` `2026-08-04` · `status:` `active`

症狀：`videotranscriber:start --id=xxx` 印出 `Starting transcription: ...`，但 `jobs`
表沒有任何新資料，也沒有任何錯誤訊息或 log。media 就這樣永遠停在原本的狀態。

原因：兩個 job 都是 `ShouldBeUnique`，`PendingDispatch::shouldDispatch()` 在派送前
會先搶 cache 鎖，搶不到就直接 return，**不 dispatch、不拋例外、不寫 log**。指令那行
`$this->info()` 在 dispatch 之前，所以照樣印出來，看起來一切正常。

鎖為什麼會殘留：`CallQueuedHandler::handle()` 只在 job 執行完或永久失敗時才釋放鎖
（`if (! $job->isReleased() && ...)`）。worker 中途被砍、容器重啟、或有人手動清掉
`jobs` 資料，鎖就會孤立地留在 redis 直到 `uniqueFor`（目前設 3600 秒）到期，這段期間
每一次 dispatch 都被丟棄。

怎麼確認：鎖是 redis key，格式為 `<CACHE_PREFIX><REDIS_PREFIX>:laravel_unique_job:<job FQCN>:<media id>`，
實際長這樣：

```
hypervel_database_hypervel_cache:laravel_unique_job:App\Jobs\Media\VideoTranscriberStartJob:01ksych9fdg4erzbtsq5jvmwgq
```

注意別用 `Cache::get()` 去讀它 —— 鎖的值是未序列化的隨機字串，`RedisStore` 會丟
`unserialize(): Error at offset 0` 警告並回傳 `false`，看起來像「key 不存在」，其實
是存在的。要判斷是否殘留請直接用 redis 的 `EXISTS` / `TTL`。

怎麼解：跑指令時加 `--force`（見下一則），或手動刪掉該 key。

## --force 不會取消已排隊的 job，可能造成同一 media 重複派送

`code:` `app/Console/Commands/VideoTranscriber/Start.php` → `releaseUniqueLock` · `updated:` `2026-08-04` · `status:` `active`

`--force` 做的事只有一件：dispatch 前把 unique 鎖丟掉。它**不會**去看佇列裡有沒有
同一個 media 的 job。

危險情境：job 因為 `VideoTranscriberAuthException` 走 `releaseForAuthRetry()` 時會
`release(300)` 退避重試。released 的 job 依然留在 `jobs` 表裡（`available_at` 在未來），
而且依框架設計**鎖會被刻意保留**，好讓重試期間不會有第二筆進來。此時若跑 `--force`，
鎖被解掉、新的 job 被派送，同一個 media 就會有兩筆 job 各自執行 `startTranscription()`，
對 videotranscriber.ai 送出兩次轉錄請求，後跑完的那筆覆蓋 `start_transcription` 欄位。

所以 `--force` 只適用於「確認鎖已經孤立」的情況。動手前先確認 `jobs` 表裡沒有該 media
的待處理資料；若有，那就不是殘留鎖，只是還沒輪到它，等就好。

順帶一提 `--id` 與 `--force` 是兩件不同的事：`--id` 繞過狀態篩選（讓 `transcribe_failed`
的 media 也能重跑），`--force` 繞過唯一鎖。兩者互不涵蓋，卡住時通常要一起下。

## videotranscriber.ai 只允許單一裝置登入，別跟人共用帳號

`code:` `app/Services/VideoTranscriber/VideoTranscriberClient.php` → `relogin` · `code:` `config/services.php` · `updated:` `2026-08-04` · `status:` `active`

videotranscriber.ai 限制同一組帳號只能有一個有效 session：**只要在別的地方登入，先前
那份 cookie 立刻失效**。這是服務端的行為，從我們的 code 完全看不出來。

為什麼對這個系統特別致命：整套系統共用**一組**帳號（`services.videotranscriber.email`
/ `password`），登入拿到的 access token 存在 `configs` 表的 `videotranscriber` 這一筆，
全域共用。所以任何一次「別的地方登入」都不是影響單一 worker，而是讓所有 job 手上的
token 一起失效，全部撞進 `VideoTranscriberAuthException` → `releaseForAuthRetry()`
退避 300 秒，最多重試 12 次。

常見的踩法：

1. 有人為了看轉錄結果，用同一組帳號登入 videotranscriber.ai 網站 → 系統的 token 當場失效。
2. 本機開發與正式環境共用同一組帳號 → 兩邊的 `relogin()` 會互相把對方踢掉，形成
   「登入 → 被踢 → 再登入」的來回循環，兩邊的 job 都在退避重試，卻查不出哪裡壞掉。

做法：**每個用途各自開一個獨立帳號** —— 正式環境一個、本機開發一個、要人工操作網站
再另開一個，彼此不共用。人要看網站時絕對不要用系統那組帳號登入。

排查提示：如果 media 大量卡在原狀態、log 裡出現連續的 auth 重試，先確認是不是有人動了
那組帳號，而不是先去追 code。
