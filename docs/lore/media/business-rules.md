---
area: media
kind: business-rules
---

# media — Business rules

<!--
Each rule is a `## heading` + a one-line meta + the body. Capture the "why" code can't show.

## Name of the rule

`code:` `path/to/file.ext` → `symbol` · `updated:` `YYYY-MM-DD` · `status:` `active`

The rule, the reasoning, and edge cases.
-->

## 一支影片在 media 表只有一列，使用者靠 userables 各自擁有它

`code:` `app/Http/Controllers/API/V1/MediaController.php` → `store()` · `updated:` `2026-08-15` · `status:` `active`

`media.resource_id` 是 unique，所以同一支 YouTube 影片不論由誰、用什麼途徑帶進來，全站只有一列。使用者與影片的關係記在 `userables`。

兩條進來的路徑共用這一列：

- **RSS 同步**（`SyncJob`）——訂閱頻道後自動收進來，`source_id` 指向該 source
- **手動貼網址**（`POST /v1/media`）——單支影片，`source_id` 是 null

手動貼的網址如果指到一支已經被 RSS 收進來的影片，會**重用**該列（連同已經跑好的逐字稿與摘要），只新增 userables 關聯。這也表示使用者可能立刻就看得到摘要，不必等轉錄。

不讓每位使用者各自持有一列，是因為轉錄與摘要的成本會直接乘上使用者數。

## 影片額度是滾動 30 天，兩條加入路徑共用同一個池子

`code:` `app/Http/Controllers/API/V1/MediaController.php` → `assertVideoQuota()`、`app/Services/SubscriptionService.php` → `syncSourceMediaToUserables()` · `updated:` `2026-08-15` · `status:` `active`

`plans.video_limit` 算的是「過去 30 天內加進 `userables` 的筆數」，`0` = 不限制。RSS 同步與手動貼網址數的是同一個數字——分開計算的話，手動加入就成了繞過 RSS 上限的後門。

`userables` 只增不減（`syncWithoutDetaching`），所以「加了再刪」無法把額度洗回來。

已經在自己影片庫裡的影片再貼一次是 no-op，不佔額度，額度就算滿了也放行——因為它不會新增 `userables` 資料列。

額度滿了回 **422**（`validators.controllers.media.video_limit_reached`），跟 `channel_limit` 同型；不是 chat 那種每日重置的 429。兩者性質不同：這裡是容量滿了，不是速率超標。

## 手動加入的影片：驗證分兩段，補資料是 best-effort

`code:` `app/Http/Controllers/API/V1/MediaController.php` → `createMediaFromUrl()` · `updated:` `2026-08-15` · `status:` `active`

1. `YoutubeService::getVideoIdFromUrl()` 純字串解析，格式不對直接 422，不浪費一次外部呼叫
2. `VideoTranscriberClient::getUrlInfo()` 確認影片真的存在且可轉錄（`code === 100000`），順便拿標題、縮圖、時長、頻道
3. `YoutubeService::getVideoDetails()` 補 `description` 與 `published_at`——`getUrlInfo` 沒有這兩個欄位

第 3 步是 **best-effort**：YouTube Data API 有配額，用盡時整個「新增影片」功能不該跟著停擺，拿不到就留空。第 2 步失敗才真的擋下來。

送給 `getUrlInfo` 的是正規化過的 `https://www.youtube.com/watch?v={videoId}`，不是使用者原本貼的網址——不把追蹤參數送到外部服務。

## 只有新建立的 media 才送去轉錄

`code:` `app/Http/Controllers/API/V1/MediaController.php` → `store()` · `updated:` `2026-08-15` · `status:` `active`

手動加入的影片建立後 `status = created` 並 dispatch `VideoTranscriberStartJob`（一律走 AI 轉錄，不看有沒有官方字幕）。

重用既有 media 時**不** dispatch：它要嘛已經跑完、要嘛正在跑，再送一次只是重複付轉錄費用。`VideoTranscriberStartJob` 的 `ShouldBeUnique` 只防「同時」，不防「跑完又跑一次」。

代價是：若那筆舊資料卡在 `transcribe_failed`，接手的新使用者也拿不到內容。重試失敗的轉錄是 `videotranscriber:start --id` 的職責，不是這個端點的。
