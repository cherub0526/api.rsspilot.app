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

worker 為什麼會「中途被砍」，最常見的原因見〈Job 超時不是砍掉那個 job，是整個 worker
process 自殺〉。

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
而且依框架設計**鎖會被刻意保留**，好讓重試期間不會有第二筆進來（這個保留行為也是
不能改用 `ShouldBeUniqueUntilProcessing` 的原因，見該則）。此時若跑 `--force`，
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

## Job 超時不是砍掉那個 job，是整個 worker process 自殺

`code:` `app/Jobs/Media/VideoTranscriberFetchJob.php` · `code:` `config/queue.php` · `updated:` `2026-08-12` · `status:` `active`

症狀：`supervisorctl status` 顯示 worker RUNNING，但過一段時間之後派送的 job 就再也
不會有作用。看起來像 worker 靜靜地失效了。

原因：`queue:work --timeout` 在 Hypervel 的語意跟直覺相反 —— 它不是「砍掉那個超時的
job」，而是「砍掉整個 worker process」。`Worker::monitorTimeoutJobs()` 每秒掃一次
執行中的 job：

```php
$this->terminateTimeoutJobs($options);

if ($this->hasTimeoutJobs()) {
    $this->shouldQuit = true;
    $this->kill(static::EXIT_SUCCESS, $options);
}
```

`kill()` 最後一行是 `posix_kill(getmypid(), SIGKILL)`，對自己送 SIGKILL。它上面那個
「等 coroutine 結束再退出」的迴圈等不到超時的那筆，因為 `terminateTimeoutJobs()` 已經
先把它從 `runningJobs` unset 掉了 —— 所以那個 job 是在還在執行的狀態下連同整個 process
一起被砍。

接著就串進〈ShouldBeUnique 的殘留鎖會讓 dispatch 靜默消失〉：SIGKILL 走不到釋放鎖那
一行，孤兒鎖留下，之後 `uniqueFor` 秒內的 dispatch 全部靜默消失。而 `autorestart=true`
會立刻把 process 拉回來，supervisor 看起來一切正常。

`VideoTranscriberFetchJob` 特別容易踩到：單次執行最多打 3～4 次外部請求
（`getTranscription()`、401 時 `relogin()` 再重放一次、成功後 `detectLocale()` 還要去
CDN 抓字幕檔判斷語言），videotranscriber.ai 一慢就破表。

三個參數的大小關係必須維持：

```
--timeout  <  retry_after  ≤  stopwaitsecs
```

- `--timeout` 大於 `retry_after`（`config/queue.php`，預設 90，由 `DB_QUEUE_RETRY_AFTER`
  覆寫）→ job 還在跑就被另一個 process 重新領走，同一支影片送出兩次轉錄請求。改
  `--timeout` 時**一定要同步改 `.env` 的 `DB_QUEUE_RETRY_AFTER`**，它不在 supervisor
  設定裡，很容易漏。
- `stopwaitsecs` 小於 `--timeout` → 每次 deploy／restart 都會 SIGKILL 在途的 job。

另外 `Worker::stop()` 只 dispatch 一個事件就 return，**不等在途的 coroutine**（跟
Laravel process-based worker「做完手上的 job 再退出」不一樣）。所以 `--max-time` 輪替
那一下若剛好有 job 在跑，仍然會被切斷。這是參數調對之後依然存在的殘留風險，只是頻率從
「外部 API 一慢就發生」降到「每小時最多一次」。

## 別把 ShouldBeUnique 換成 ShouldBeUniqueUntilProcessing

`code:` `app/Jobs/Media/VideoTranscriberFetchJob.php` · `code:` `app/Console/Commands/VideoTranscriber/Fetch.php` · `updated:` `2026-08-12` · `status:` `active`

想解決孤兒鎖時，很自然會想到改用 `ShouldBeUniqueUntilProcessing` —— 開始執行就放鎖，
process 怎麼死都不會留下孤兒。**對這兩支 job 不能這樣做，會比原本的問題更糟。**

`CallQueuedHandler::handle()` 的釋放條件帶了 `! $job->isReleased()`：job 把自己 release
出去等重試時，鎖是**刻意保留**的，好讓退避期間不會有第二筆進來。而這兩支 job 大量依賴
這個行為：

- `VideoTranscriberFetchJob` 沒有任何版本 ready 時 `release(60)`，最多 60 次 → 單一
  media 可以合法佔用約 1 小時
- 走 `releaseForAuthRetry()` 時 `release(300)`，最多 60 次 → 最長約 5 小時

換成 UntilProcessing 之後，鎖在第 1 次嘗試「開始執行」的瞬間就消失，而且不會再被重新
取得，整段退避期間完全沒有保護。致命的是 `Fetch.php` 不帶 `--id` 時撈的正是
`status = transcribing` 的 media，而退避重試中的 media 狀態就是 `transcribing` —— 那
1～5 小時內每跑一次指令就多排一筆，跑兩次就三筆。

正解是不要讓 process 被 SIGKILL（見上一則的參數設定），而不是把鎖變弱。

順帶一個既有缺口：`uniqueFor` 目前是 3600，但 auth 退避路徑最長可以跑 5 小時。第 1 小時
後鎖自然到期、job 卻還在重試，這段期間新的 dispatch 會被接受。觸發條件窄（要同時碰上
auth 失敗又剛好有人跑指令），但確實存在。要補就是把 `uniqueFor` 拉到涵蓋最長重試週期，
代價是孤兒鎖的影響時間也一起變長 —— 是個取捨，不是單純的修正。
