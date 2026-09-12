---
area: transcription
kind: business-rules
---

# transcription — Business rules

<!--
Each rule is a `## heading` + a one-line meta + the body. Capture the "why" code can't show.

## Name of the rule

`code:` `path/to/file.ext` → `symbol` · `updated:` `YYYY-MM-DD` · `status:` `active`

The rule, the reasoning, and edge cases.
-->

## 轉錄 pipeline 靠 media.status 交棒，不是靠 job 串 job

`code:` `app/Console/Kernel.php` · `code:` `app/Console/Commands/VideoTranscriber/` · `code:` `app/Jobs/Media/VideoTranscriberFetchJob.php` · `updated:` `2026-09-13` · `status:` `active`

三個階段之間沒有任何 job 直接呼叫下一個 job。每支指令每分鐘跑一次、用 `media.status`
撈出屬於自己那一段的 media 派工，狀態就是交接點：

```
created →(start)→ transcribing →(fetch)→ transcribed →(summarize)→ summarizing → summarized
                        ↘ transcribe_failed        ↘ summarize_failed
```

**能每分鐘重跑而不重複派工的原因，是 job 一開工就把狀態改走**，於是下一輪的查詢條件
就不再涵蓋它（`VideoTranscriberSmartSummaryJob` 進場先寫 `summarizing` 就是為了這件事），
再加上 `ShouldBeUnique` 擋同一 media 的併發。要新增一個階段，就得回答「它擁有哪個狀態」，
否則排程會一直重派。

推論出來的兩個性質：

1. **失敗狀態是終點。**`transcribe_failed` / `summarize_failed` 不在任何指令的預設查詢
   裡，media 不會自己回來，只能用 `--id` 手動重跑（`--id` 的存在就是為了繞過狀態篩選）。
2. **中間狀態代表「有人正在處理或正在退避重試」**，不代表卡住。fetch 的退避期間 media
   就是停在 `transcribing`。

**唯一的例外是 `VideoTranscriberArchiveJob`**：它由 `VideoTranscriberFetchJob` 在成功
寫完 caption 後直接 dispatch，而不是由指令撈狀態派工。因為它不擁有任何 media 狀態 ——
歸檔成功與否都不該改變 media 的處境。代價是**既有的 media 沒有自動補派的機制**：它只在
「這次剛轉錄完」的那一刻被派一次，要補跑舊資料得用 `videotranscriber:archive --id=`。
這支指令刻意不排進 scheduler，也刻意不支援批次，因為每支 media 會拉一份十幾 MB 的 mp3。

## 新增 queue 一定要同步開 worker，兩邊都要

`code:` `.railway/railway.ts` · `code:` `supervisor/` · `updated:` `2026-09-13` · `status:` `active`

job 的 `$this->queue` 只是寫進 `jobs` 資料表的一個字串，沒有任何機制保證有人在聽。
**新開一個 queue 而沒有 worker 訂閱時，dispatch 會成功、資料列會躺在 `jobs` 表裡，
沒有錯誤、沒有 log、也不會進 `failed_jobs`** —— 從應用程式這一側完全看不出來。

而且要改的地方有兩處，因為 Forge 與 Railway 並行且不共用設定：

- Forge：`supervisor/<queue>.conf`（一個 queue 一個檔）
- Railway：`.railway/railway.ts` 裡 worker service 的 `--queue` 清單

Railway 只有 `worker-fast`（`--timeout=120`）與 `worker-slow`（`--timeout=300`）兩個
service，因為一個 `queue:work` 只能有一個 `--timeout`。新 queue 要放哪一個，取決於它
單次執行的最壞耗時 —— 這個判斷必須做，超時的代價是整個 worker 自殺（見 pitfalls）。
