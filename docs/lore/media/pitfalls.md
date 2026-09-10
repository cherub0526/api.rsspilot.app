---
area: media
kind: pitfalls
---

# media — Pitfalls

<!--
Each pitfall is a `## heading` + a one-line meta + the body:

## Short title of the gotcha

`code:` `path/to/file.ext` → `symbol` · `updated:` `YYYY-MM-DD` · `status:` `active`

What breaks, why, and what to do instead.
-->

## resource_id 帶 `yt:video:` 前綴，video_detail['yt:videoId'] 才是純 ID

`code:` `app/Services/YoutubeService.php` → `getPlaylistItems()` · `updated:` `2026-08-15` · `status:` `active`

同一支影片的識別在兩個地方長得不一樣：

| 欄位 | 值 |
|---|---|
| `media.resource_id` | `yt:video:uXHNRFHWDnM` |
| `media.video_detail['yt:videoId']` | `uXHNRFHWDnM` |

前綴來自 RSS feed 的 entry id，`getPlaylistItems()` 沿用了它。任何要以影片 ID 查 `media` 的程式都必須自己補上 `yt:video:`，否則查不到既有資料列——而 `resource_id` 是 unique，接著的 `create()` 會直接違反約束，或（更糟）在有 `firstOrCreate` 的地方悄悄建出重複的影片。

## 下游 job 直接讀 video_detail['yt:videoId']，沒有防呆

`code:` `app/Jobs/Media/VideoTranscriberStartJob.php` → `handle()`、`app/Jobs/Media/YoutubeCaptionJob.php` → `handle()` · `updated:` `2026-08-15` · `status:` `active`

`VideoTranscriberStartJob`、`YoutubeCaptionJob`、`InfoJob` 與 `MediaResource` 都以 `$media->video_detail['yt:videoId']` 取影片 ID，前三者**沒有 null 檢查**。

也就是說：任何新的 media 建立途徑，`video_detail` 都必須至少帶 `yt:videoId`，否則 job 一跑就 crash，而且是在 queue 裡炸、不是在請求裡。`MediaController::store()` 因此把 `video_detail` 組成與 RSS 相容的形狀，不是為了好看。

（`YoutubeDataCaptionJob` 是唯一有 fallback 的：拿不到就去 parse `resource_id`。）

## videotranscriber:start 沒有排進 schedule

`code:` `app/Console/Kernel.php` → `schedule()` · `updated:` `2026-08-15` · `status:` `active`

`Kernel::schedule()` 目前只排了 `sources.sync`（每日 00:00）。`videotranscriber:start` 雖然會撈所有 `status = created` 的 media 送去轉錄，但**沒有任何排程會呼叫它**，只能手動執行。

所以「建好 media，狀態留在 `created`，等排程處理」這條路實際上不成立——新的建立路徑要自己 dispatch job，否則影片會永遠停在 `created`。

## 取「可用的摘要」不能只看 created_at，要 filter status = completed

`code:` `app/Http/Controllers/API/V1/Media/ChatController.php` → `chat()`、`app/Http/Controllers/API/V1/Media/Chat/FollowUpsController.php` → `show()` · `updated:` `2026-08-19` · `status:` `active`

要拿摘要當 AI 素材時，正確寫法是：

```php
$media->summaries()
    ->where('status', Summary::STATUS_COMPLETED)
    ->orderByDesc('created_at')
    ->first()?->text
```

**不要**用 `Media::summary()` relation，也不要只 `orderByDesc('created_at')->first()`。`summaries.status` 預設是 `created`，而 `SummaryJob` / `VideoTranscriberSmartSummaryJob` 都是先 `firstOrCreate(['locale' => ...])` 建好資料列、**再**去跑模型，中間那段時間資料列存在但 `text` 是空的。換語系重跑會因為 locale 不同真的新增一列，於是「最新的一列」正好是那筆空的，把先前跑好、還能用的摘要蓋掉——症狀是使用者原本看得到摘要，一按重新生成，chat 與延伸問題的素材就突然變空字串。

`Media::summary()` 這個 relation 本身就是 `hasOne(...)->orderBy('created_at', 'desc')`，天生踩在這個陷阱上，只適合拿去顯示、不適合當 AI 素材。

**寫入端的對應義務（曾經漏過）：** 任何寫摘要的路徑都必須把 status 一起寫成 `completed`，否則那份摘要對讀取端等於不存在。`POST /v1/webhook/summaries/{mediaId}`（`Webhook\SummariesController::store()`）原本只寫 `text`、只把 media 改成 `summarized`，摘要本身永遠停在 `created`——因為線上摘要都走 job，這個洞一直沒被踩到。已於 2026-08-19 補上。新增第三條寫入路徑時記得同樣處理。

## `rss` 表已停用，別跟「YouTube RSS feed」搞混

`code:` `database/migrations/2025_10_30_170450_create_rss_table.php` · `code:` `app/Services/RssFeedAsapService.php` · `updated:` `2026-09-10` · `status:` `active`

**`rss` 這張表是第一代的訂閱來源儲存，已經停用，由 `sources` 取代。不要再對它寫入、不要擴充它、也不要把殘留的 7 筆資料遷移過去。**

從程式碼看不出這件事，因為停用得不乾淨——留下三樣會誤導人的東西：

| 殘留 | 狀態 |
|---|---|
| `rss` 資料表 | 還在，本機還有 7 筆資料 |
| 兩支 migration | 還在（`create_rss_table`、`add_list_id_to_rss_table`） |
| `App\Services\RssFeedAsapService` | 還在（19 行、`execute($url)`），但**全專案沒有任何呼叫端** |

沒有 Model、沒有 Controller、沒有路由、沒有 Resource——只剩上面這三樣。另外 `AGENTS.md` 的 Key Services 一節仍把 `RssFeedAsapService` 列為「Parses YouTube RSS feeds」，那份描述現在是錯的。

兩張表的意圖對照：

```
rss     : id, type, title, url, comment, list_id
sources : id, type, external_id, title, url, thumbnail, description,
          metadata, last_synced_at, status, free
```

`sources` 多出來的欄位正是它取代前者的理由——`external_id` 才能對得上 YouTube 的頻道／播放清單 ID、`last_synced_at` 支撐同步排程、`free` 與 `status` 是產品規則的執行點。

### 用詞陷阱

「RSS」在這個專案有兩個完全無關的意思，混用會讓人以為某段程式碼碰到了停用的表：

- **`rss` 表** —— 停用的第一代儲存（本條）
- **YouTube 的 XML feed** —— `https://www.youtube.com/feeds/videos.xml?channel_id=...`，存在 `sources.url`，由 `Sources\Sync::fetchRssEntries()` 以 `Http::get()` 抓回來解析成 `media`。**這條路是活的，而且跟 `rss` 表零交集**——整個 `Sync` 指令裡一個 `rss` 字樣都沒有。

討論「RSS 同步」時指的一律是後者。
