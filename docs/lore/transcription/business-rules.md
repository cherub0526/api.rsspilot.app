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

`code:` `app/Console/Kernel.php` · `code:` `app/Console/Commands/VideoTranscriber/` · `code:` `app/Jobs/Media/VideoTranscriberFetchJob.php` · `code:` `app/Jobs/Media/SummaryTranslationJob.php` · `updated:` `2026-09-16` · `status:` `active`

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

**例外是那些「不擁有任何 media 狀態」的後續工作**，目前有兩支，都由前一支 job 直接
dispatch 而不是由指令撈狀態派工：

| job | 由誰派 | 為什麼不能有自己的狀態 |
|---|---|---|
| `VideoTranscriberArchiveJob` | `VideoTranscriberFetchJob` 寫完 caption 後 | 歸檔成功與否都不該改變 media 的處境 |
| `SummaryTranslationJob` | `VideoTranscriberSmartSummaryJob` 寫完摘要後 | media 有了來源語言的摘要就是真的 `summarized`；少一種翻譯不該讓它看起來沒做完 |

翻譯這一支還多一層理由：`Media::summaryFor()` 本來就會在找不到該語系時退回共用摘要，
所以缺翻譯對使用者是**降級**而不是壞掉，不值得用一個 media 狀態去追蹤。它改用
「`summaries` 一列一語系」承接進度——一個語系一支 job，各自重試、各自失敗。

**代價是兩支都沒有自動補派的機制**：只在「這次剛做完」的那一刻被派一次。

- 歸檔要補跑舊資料用 `videotranscriber:archive --id=`。這支指令刻意不排進 scheduler，
  也刻意不支援批次，因為每支 media 會拉一份十幾 MB 的 mp3。
- 翻譯**連補跑的指令都還沒有**：這個功能上線前就摘要完的 media 一律沒有翻譯，
  要補得另外寫。判斷「要不要補」時記得它是靠 `available_locales` 展開的——
  之後多開一個語系，同樣只有新影片會有，既有的全部缺。

## summaries.locale 記的是摘要的語言，不是字幕的語言

`code:` `app/Jobs/Media/VideoTranscriberSmartSummaryJob.php` → `languageFor` · `code:` `app/Console/Commands/VideoTranscriber/Summarize.php` · `updated:` `2026-09-18` · `status:` `active`

兩條規則要一起看，缺一條就會產生「標示與內容不符」的資料：

1. **影片是什麼語言，主摘要就是什麼語言**——`videotranscriber:summary` 不帶
   `--language` 時，語言跟著 primary caption 的語系走。`--language` 是明確覆寫，
   重跑成別的語言時才用。
2. **`summaries.locale` 存的是這份摘要「寫成什麼語言」**，而不是它的字幕語系。

2026-09-18 之前這兩件事是錯開的：指令預設 `--language=en`，資料列卻存字幕語系。
於是中文影片會產出一列**標著 `zh-CN`、內容卻是英文**的摘要（staging 實測）。

這不只是標示難看，它會讓翻譯整個失效：`SummaryTranslationJob` 以這一欄當來源語言
展開目標語系（`available_locales` 扣掉來源），所以

- 系統以為 `zh-CN` 已經有了 → **真正的中文版永遠不會被產生**
- 反而去產一份 `en` → **把英文「翻譯」成英文**，白花一次推論

排查提示：看到「中文影片的摘要是英文」不要先去看 prompt，先確認那一列的 `locale`
跟內容語言是否一致——症狀出現在翻譯，根因在這裡。

**既有資料沒有回填。**這個修正只影響之後產生的摘要；在那之前的共用摘要仍然是
「內容英文、locale 是字幕語系」，要不要清一次是另一個決定（見下段的取捨）。

回填會遇到的問題：光看 `locale` 分不出「舊的錯誤標示」與「新的正確標示」，得靠
`ai_model` 反推（`VideoTranscriberClient::SUMMARY_MODEL` 產的是來源摘要、
OpenRouter 模型產的是翻譯），而且改標之後同一支 media 可能出現兩列 `en`。

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

## 公開字幕閘門：只收 YouTube 上已有公開字幕的影片

`code:` `app/Services/PublicCaptionGate.php` · `code:` `app/Http/Controllers/API/V1/MediaController.php` → `store()` · `code:` `app/Jobs/Media/VideoTranscriberStartJob.php` → `passesCaptionGate()` · `code:` `app/Services/YoutubeService.php` → `hasCaptionTracks()` · `updated:` `2026-10-03` · `status:` `active`

`MEDIA_REQUIRE_PUBLIC_CAPTIONS=true`（`services.youtube.require_public_captions`）時，影片必須在
YouTube 上已經有字幕軌才會進入轉錄：作者上傳的 `standard` 或自動產生的 `asr` 都算，只有
`forced`（只翻片中外語片段）或處理失敗（`status = failed`）的軌道不算。預設關閉，關閉時行為與
以前完全一樣。

- **自動字幕查得到**：2026-10-03 實測，`captions.list` 對別人的公開影片也會列出 `asr` 軌——
  `9bZkp7q19f0` 只有一條 `asr ko`，會被判為有字幕。

- **手動新增**：沒有字幕回 422 `no_public_captions`、不建 media；查不到（配額、網路）回 422
  `captions_check_failed`，**不放行**——閘門的意義就是確定有字幕才收。檢查排在額度檢查之後，
  額度已滿的請求不花配額。
- **頻道同步**：`sources:sync` 照常建 media，由 `VideoTranscriberStartJob` 開工前檢查。沒有字幕
  就改成 `no_captions`（終點狀態，不轉錄、不摘要）；查不到就 release 十分鐘後重查，media 留在
  `created`。
- **額度**：`no_captions` 的影片不算進影片額度（`Media::scopeCountsTowardQuota`），手動新增、
  同步、用量端點三處都走這個 scope。
- **配額**：判斷用官方 Data API `captions.list`，每次 50 單位，預設每日 10,000 單位約 200 支。
  結果記在 `video_detail['public_captions']`，同一支影片只查一次；`MediaFactory` 隨機狀態刻意
  排除 `no_captions`，否則額度測試會偶發失敗。

**這只是「收不收」的閘門，字幕內容仍走 videotranscriber.ai。** 官方 API 拿得到「有沒有字幕」，
拿不到字幕內容：`captions.download` 只限影片擁有者（OAuth），而舊的 `timedtext` 端點
（`YoutubeDataCaptionJob`）現在要求 proof-of-origin token，2026-10-02 實測對有字幕的影片也回
`200` 加 0 bytes。要改成真正用公開字幕當系統字幕，得另接第三方字幕 API。

作者事後補上字幕不會自動回來：`no_captions` 不在任何排程的查詢條件裡，要用
`videotranscriber:start --id=` 手動重送（`public_captions` 是 false 時會重新查）。
