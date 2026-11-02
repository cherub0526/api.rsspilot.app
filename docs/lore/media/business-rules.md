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
- **手動貼網址**（`POST /v1/media`）——單支影片，會順手把該影片的頻道建成 source
  並掛上去（`SourceService::firstOrCreateYoutubeChannel()`）。只有 `getUrlInfo`
  與 YouTube Data API 都拿不到頻道時才留 null

手動貼的網址如果指到一支已經被 RSS 收進來的影片，會**重用**該列（連同已經跑好的逐字稿與摘要），只新增 userables 關聯。這也表示使用者可能立刻就看得到摘要，不必等轉錄。

不讓每位使用者各自持有一列，是因為轉錄與摘要的成本會直接乘上使用者數。

## 手動加入影片會建出頻道 source，但不等於訂閱該頻道

`code:` `app/Services/SourceService.php` → `firstOrCreateYoutubeChannel()`、`app/Console/Commands/Sources/Sync.php` → `handle()` · `updated:` `2026-11-02` · `status:` `active`

`POST /v1/media` 建立 media 時會一併建立（或重用）該影片所屬頻道的 source，讓手動加入的影片跟 RSS 收進來的一樣掛得到來源——列表能顯示頻道、關鍵字能搜到頻道名、日後真的訂閱該頻道時，這支影片也算是「該來源既有的 media」，訂閱時可以勾選（`SourceSubscriptionService::subscribe()`）。

**但使用者不會因此被寫進 `user_sources`。** 訂閱會吃掉方案的 `channel_limit`，只加一支影片不該偷偷佔掉一個頻道名額。「擁有影片」與「訂閱來源」是兩個樞紐，混用出過事（見〈影片的存取權以 userables 為準〉）。

於是產生一種來源：**存在、active、但沒人訂閱且 `free = false`**。`sources:sync` 因此只撈「`free = true` 或至少有一位使用者訂閱」的 active 來源——照單全收會替沒人要的頻道抓整份 RSS、建整批 media、再送去轉錄，成本卻沒有任何使用者在對應。

例外是 `--id`：明確指定單一來源代表人工意圖（補跑、驗證），略過這道過濾，只保留 `status = active` 的判斷。`--free` 則是在過濾之上再收窄，不是放寬。

新來源預設 `free = false`，所以手動加入影片不會意外讓整個頻道進入同步排程。

## 影片額度是滾動 30 天，兩條加入路徑共用同一個池子

`code:` `app/Http/Controllers/API/V1/MediaController.php` → `assertVideoQuota()`、`app/Services/SubscriptionService.php` → `remainingVideoQuota()` · `updated:` `2026-11-02` · `status:` `active`

`plans.video_limit` 算的是「過去 30 天內加進 `userables` 的筆數」，`0` = 不限制。訂閱來源時勾選的影片、`sources:sync` 收進來的新影片、手動貼網址，三條路數的是同一個數字——分開計算的話，手動加入就成了繞過 RSS 上限的後門。

訂閱時勾選的影片（扣掉已在影片庫的）超過剩餘額度，整筆訂閱回 422、不建立；同步時額度不夠則跳過，見〈訂閱只收同步時間點之後的影片〉。

注意兩處的窗口不完全相同：`MediaController::assertVideoQuota()` 用 `subDays(29)`（兩端含，剛好 30 個日期），`remainingVideoQuota()` 與用量端點沿用 `subDays(30)`。

`userables` 只增不減（`syncWithoutDetaching`），所以「加了再刪」無法把額度洗回來。

已經在自己影片庫裡的影片再貼一次是 no-op，不佔額度，額度就算滿了也放行——因為它不會新增 `userables` 資料列。

額度滿了回 **422**（`validators.controllers.media.video_limit_reached`），跟 `channel_limit` 同型；不是 chat 那種每日重置的 429。兩者性質不同：這裡是容量滿了，不是速率超標。

## 訂閱只收同步時間點之後的影片

`code:` `app/Services/SourceSubscriptionService.php` → `subscribe()`、`deliverNewMedia()`、`app/Console/Commands/Sources/Sync.php` · `updated:` `2026-11-02` · `status:` `active`

每筆訂閱（`user_sources`）有一個同步時間點 `synced_at`：

- **訂閱時**：只把使用者在預覽（`GET /v1/sources/preview`）勾選的影片加進影片庫，預設一支都不勾，不勾也能訂閱。新訂閱的 `synced_at` = 送出當下；對已訂閱的來源再送一次只改 `notify`，**不重設** `synced_at`（勾選的影片照常加入）。
- **同步時**：`sources:sync` 對每位訂閱者只加入 `media.created_at` 落在 `(synced_at, 本次同步時間]`、且還不在影片庫的影片，依 `published_at` 新到舊、受剩餘額度限制，然後把 `synced_at` 推到本次同步時間。每次同步都推，不管有沒有新影片。

2026-11-02 之前的規則是「該來源的影片只要還不在影片庫就補進去」（已刪除的 `syncSourceMediaToUserables()`），舊影片會佔掉額度，而且額度一空出來又被補回去，使用者無法預期影片庫會多出什麼。四個取捨都是使用者決定的：

1. **時間基準是系統第一次看到影片的時間（`media.created_at`），不是 `published_at`。** YouTube RSS 會延遲，發布時間早於訂閱、但訂閱後才出現在 RSS 的影片若比照發布時間就永遠收不到。
2. **額度用完就跳過，之後不補，`synced_at` 照樣往前推。** 補的話又回到「額度空出來就塞舊影片」的老問題。
3. **既有訂閱的 `synced_at` 設為 migration 執行時間**（`2026_11_02_100000_add_synced_at_to_user_sources_table`），上線後不再補舊影片。release note 要寫。
4. **預設全不勾，可以零支。** 影片庫只該出現使用者明確要的東西。

### 訂閱時要把 RSS 現有影片全部寫進 media

時間基準是 `media.created_at`，所以訂閱時 RSS 裡**沒勾**的影片如果還沒有 media 列，下一次同步才建出來，`created_at` 會晚於 `synced_at`，就被當成新影片補進影片庫。`subscribe()` 因此先把目前 RSS 的每一支都 upsert 成 media（`status = created`，照常由排程送去轉錄——同步本來也會建），再寫 `synced_at`。

### 勾選的影片必須屬於這個來源

`video_ids` 是 11 碼 YouTube ID，`media.resource_id` 存的是 RSS entry id `yt:video:<videoId>`，比對前要加前綴。每個 ID 必須在目前的 RSS 清單裡，**或已是該來源既有的 media**——預覽到送出之間 RSS 可能更新，後者避免誤擋。都不是就 422（`validators.controllers.sources.video_not_in_source`），不建立訂閱。

YouTube RSS 只有最新 15 筆，更舊的影片只能用手動貼網址加入。舊版桌面 app 不送 `video_ids`，訂閱後影片庫不會多影片。

## 手動加入的影片：驗證分兩段，補資料是 best-effort

`code:` `app/Http/Controllers/API/V1/MediaController.php` → `createMediaFromUrl()` · `updated:` `2026-08-15` · `status:` `active`

1. `YoutubeService::getVideoIdFromUrl()` 純字串解析，格式不對直接 422，不浪費一次外部呼叫
2. `VideoTranscriberClient::getUrlInfo()` 確認影片真的存在且可轉錄（`code === 100000`），順便拿標題、縮圖、時長、頻道
3. `YoutubeService::getVideoDetails()` 補 `description` 與 `published_at`——`getUrlInfo` 沒有這兩個欄位；
   `getUrlInfo` 偶爾漏掉的 `channel_id` / `author` 也由同一份 snippet 的 `channelId` / `channelTitle` 補回

第 3 步是 **best-effort**：YouTube Data API 有配額，用盡時整個「新增影片」功能不該跟著停擺，拿不到就留空（頻道拿不到就是 `source_id` 留 null）。第 2 步失敗才真的擋下來。

頻道走這條路補是免費的：`getVideoDetails()` 為了 description 本來就會發，而且它請求的 part 已經含 `snippet`，所以拿頻道不會多打一次 API、也不會多耗配額。

送給 `getUrlInfo` 的是正規化過的 `https://www.youtube.com/watch?v={videoId}`，不是使用者原本貼的網址——不把追蹤參數送到外部服務。

## 只有新建立的 media 才送去轉錄

`code:` `app/Http/Controllers/API/V1/MediaController.php` → `store()` · `updated:` `2026-08-15` · `status:` `active`

手動加入的影片建立後 `status = created` 並 dispatch `VideoTranscriberStartJob`（一律走 AI 轉錄，不看有沒有官方字幕）。

重用既有 media 時**不** dispatch：它要嘛已經跑完、要嘛正在跑，再送一次只是重複付轉錄費用。`VideoTranscriberStartJob` 的 `ShouldBeUnique` 只防「同時」，不防「跑完又跑一次」。

代價是：若那筆舊資料卡在 `transcribe_failed`，接手的新使用者也拿不到內容。重試失敗的轉錄是 `videotranscriber:start --id` 的職責，不是這個端點的。

## 影片的存取權以 userables 為準，不是來源訂閱

`code:` `app/Models/Media.php` → `isAccessibleBy()` · `updated:` `2026-11-02` · `status:` `active`

判斷只有兩條，任一成立即可存取：

1. **使用者的影片庫裡有這支影片**（`userables` 樞紐，`$user->media()`）
2. 使用者沒有它，但**該影片的來源 `free = true`**

實測全矩陣：

| `source.free` | 在影片庫 | 結果 |
|---|---|---|
| true | 是 | 可存取 |
| true | 否 | 可存取 |
| false | 是 | 可存取 |
| false | 否 | 404 |
| 無 source | 是 | 可存取 |
| 無 source | 否 | 404 |

`source` 為 null 時第 2 條直接是 false（`$this->source?->free ?? false`），所以未歸屬任何來源的影片只能靠影片庫授權。

手動加入的影片現在多半**有** source（見〈手動加入影片會建出頻道 source〉），但那個 source 預設 `free = false`，第 2 條一樣不成立——「不訂閱來源、直接加單支影片」的授權仍然只靠影片庫。真正的差別只在該頻道剛好被標成免費來源時：那支影片就對所有人開放，這是 free 來源本來就要的行為。

### 為什麼不是看來源訂閱

使用者可以**不訂閱任何來源、直接把單支影片加進影片庫**。那種影片在 `user_sources` 裡沒有對應的列，用來源訂閱判斷會誤判成無權存取。

反過來也不對稱：訂閱來源**不會**把該來源的影片全部寫進影片庫——只寫使用者訂閱時勾選的、以及同步時間點之後才出現的新影片，而且受 30 天影片額度限制（見〈訂閱只收同步時間點之後的影片〉）。來源的舊影片、額度用完被跳過的新影片都不在使用者的影片庫裡，自然也不該讀得到字幕或摘要。

### 用詞陷阱

「訂閱」在這個專案有兩個意思，混用過一次就出過事：

- **訂閱來源** = `user_sources` 樞紐（`$user->sources()`）
- **擁有影片** = `userables` 樞紐（`$user->media()`）

存取權看的是後者。

### 曾經有三套不同的判斷

2026-08-29 收斂前，同一個概念散在三處且互不等價：

| 端點 | 當時的判斷 |
|---|---|
| `media/{id}`、`media/{id}/chat/*` | `Media::isAccessibleBy`（正確的那套） |
| `media/{id}/captions*` | `Source::isAccessibleBy`——看**來源訂閱** |
| `media/{id}/summaries*` | `$user->media()->find()`——看綁定但**不看 free** |

症狀是同一個使用者對同一支影片，captions 看得到、chat 回 404，或免費來源的影片有字幕卻沒有摘要。全部改為呼叫 `Media::isAccessibleBy`。

新增需要授權的端點時直接呼叫它，不要另外寫一份。

## 播放器截圖以內容定址，S3 的路徑就是那筆紀錄

`code:` `app/Services/ThumbnailService.php` → `path()` · `updated:` `2026-09-13` · `status:` `active`

截圖存在 `media/{mediaId}/thumbnails/{秒數補零6位}.{sha256}.jpg`。key 完全由
`(mediaId, second, checksum)` 推導得出，所以「這張圖存過了嗎」問 S3 就有答案，不需要另一張表去描述 S3 已經知道的事。

連帶消失的三個問題，是選這個設計而不是「另開 `chat_screenshots` 表 / 擴充 `images` 表」的實際理由：

- **孤兒列**——截了圖但沒送出訊息，只是留下一張別人也能重用的快取，不是要清的垃圾
- **並發**——兩個人同時送同一張圖就是寫同一個 key，內容相同，不需要 unique index 也不需要鎖
- **簽章過期**——`chat_messages.parts` 的 image 片段只存 `second` 與 `checksum`，URL 由 `ChatMessageResource` 在輸出當下才簽。存 URL 進 parts 的話，隔天回頭看同一段對話就是一排破圖

**識別靠的是 checksum，不是秒數。** 秒數留在路徑裡只為了可讀與可排序（人工翻 bucket 時看得出這張圖在影片的哪裡）；一秒有 24–60 幀，用它當身分會出事，理由見 `pitfalls.md`〈秒級 key 會讓同一秒的不同幀互相頂替〉。

`images` 表（polymorphic，feedback 附圖在用）被評估過但沒採用：它缺 `user_id` / `checksum` / `second`，而且 `foreign_*` 對不上時序——截圖發生在第一則訊息之前，那時 `ChatSession` 還不存在。

副檔名、補零位數與 checksum 的大小寫都是 key 的一部分，改動等於讓既有截圖全部失去命中，所以定義成 `ThumbnailService` 的常數（`CHECKSUM_REGEX` 只收小寫 hex，否則同一份內容會有兩個 key）。用 JPEG 而不是 PNG：1280px 的影片畫面存 PNG 約 1.5–3MB、JPEG 約 150KB。

## 內容定址讓「替換掉別人看到的畫面」不再可能

`code:` `app/Http/Controllers/API/V1/Media/ThumbnailsController.php` → `store()` · `updated:` `2026-09-13` · `status:` `active`

路徑裡沒有 `user_id`，所以同樣的 bytes 全站只存一份；但因為 key 是**從內容推導出來的**，寫入者也就沒辦法把別人引用的那張圖換成別的內容——要寫到某個 key，手上就得先有算出那個 key 的 bytes。

這取代了早期版本的 first-write-wins。當時 key 只有秒數，「不覆寫」是唯一能防止後來者替換畫面的手段；改成內容定址之後，覆寫本身已經無害（同一個 checksum 就是同一份 bytes），跳過寫入只是省一次沒有意義的 PUT。

`checksum` 由前端算、由後端**重算比對**（`hash_equals`）。採信客戶端自己說的值等於讓它把任意內容擺到任意 key 上，上面那個保證就沒了。

另外兩道防線不變：`second` 要落在 `media.duration` 內（`duration` 為 0 代表還沒抓到片長，此時上界由 `MAX_SECOND` 接手），以及 `resolveMedia()` 的存取權檢查照常跑（共用的是圖，不是看影片的權限）。

## 截圖只開放給 Advance，閘門設在兩處

`code:` `app/Http/Controllers/Concerns/ResolvesUserPlan.php` → `assertScreenshotEnabled()` · `updated:` `2026-09-13` · `status:` `active`

判準是 `plans.screenshot_enabled`（目前只有 Advance 為 true），不是比對方案名稱——那個欄位本來就是產品用來表達權益的方式，寫死方案名稱會在新增方案或調整權益時，程式與資料各說各話。沒有方案時一併擋下：無從判斷權益的預設是不給。

**權益範圍改過一次。** 最初開放給所有付費方案（`add_screenshot_enabled_to_plans_table` 依 `download_enabled` 回填），後來收斂成只有 Advance（`restrict_screenshots_to_advance_plan`）。因為執行期只讀旗標，這次調整沒有動到任何一行判斷邏輯——只有資料與文案。那兩支 migration 的 `where('title', ...)` 是一次性資料修正要指名既有資料列，與執行期的判斷無關。

**擋在兩個地方，不是一個：**

1. `POST /v1/media/{mediaId}/thumbnails` —— 上傳截圖
2. `POST /v1/media/{mediaId}/chat`，而且只在 `images` 非空時 —— 帶圖提問

第二處才是真正必要的。截圖是內容定址的共用物件，理論上可以引用別人已經存過的同一張畫面直接問，完全跳過上傳；而**成本落在推論而不是儲存**——vision 的單次成本明顯高於純文字（每日額度因此為帶圖提問扣 2 點，見 `docs/lore/prompts/business-rules.md`〈帶圖提問扣 2 點〉）。只擋上傳等於把閘門設在便宜的那一端。

純文字提問完全不受影響，所以條件是「有帶圖才檢查」而不是「這個方案能不能用 chat」。

**`GET /thumbnails/{second}/{checksum}` 不設閘門**，它只是「這張存過了嗎」的查詢，而且要先有 checksum 才問得出來——問得出來就代表已經截過了。

**歷史裡的截圖不受影響。** `ChatMessageResource` 簽 URL 時不看方案：降級之後回頭看舊對話仍該看得到圖，讓歷史破圖不是權益該有的表達方式。`ScreenshotPlanGateTest::testDowngradedUserStillSeesScreenshotsInHistory` 釘住這件事。

前端的 `captureLocked` 只是把鈕變灰、掛上 Advance 標記（刻意不隱藏——藏起來使用者就不知道有這個功能，也不會想升級），真正的判定在伺服器。

## 全站功能開關：預設關閉就是送審狀態

`code:` `config/features.php` · `code:` `app/Enums/Feature.php` · `code:` `app/Http/Middleware/EnsureFeatureEnabled.php` · `updated:` `2026-10-31` · `status:` `active`

`config/features.php` 是「這台伺服器有沒有這個功能」，與方案權益（`plans.*_enabled`）是兩回事，
兩者都開才給。預設全部關閉，關閉的樣子就是通過 Creem 帳號審核的樣子；要開哪一項由使用者決定。

| config key | env | 關閉時 |
|---|---|---|
| `transcript_download` | `FEATURE_TRANSCRIPT` | `GET /v1/media/{id}/captions/{captionId}/download` 回 404 |
| `channel_skill` | `FEATURE_CHANNEL_SKILL` | `GET /v1/sources/{id}/skill` 與 `/skill/download` 回 404；`sources:skills` 什麼都不做；`PlanResource.skill_enabled` 回 false |

範圍是使用者 2026-10-31 指定的，有幾處刻意**沒有**擋：

- **逐字稿只擋「匯出成檔案」。** App 內檢視（`captions/{id}` 回完整 segments）與 MCP 的
  `get_transcript`（一次最多 500 段原文）都照舊，仍然輸出完整原文。env 名稱是使用者指定的
  `FEATURE_TRANSCRIPT`，config key 用比較準確的 `transcript_download`。
- **截圖不做開關**，照舊只看 `plans.screenshot_enabled`。
- **對話與摘要本來就讀字幕**：使用者在對話裡要求「把逐字稿貼出來」時，模型可能照做。這是
  開關蓋不到的地方。

路由上的寫法是 `'feature:' . Feature::X->value`，排在 `auth` 之後（沒登入照樣 401）、
`throttle` 之前（關閉的功能不吃額度）。404 而不是 403：關閉的功能在這台伺服器上就是不存在。

`phpunit.xml.dist` 把開關釘成 false；驗功能本身的測試在 `setUp` 用
`config(['features.<key>' => true])` 自己打開，關閉時的行為各有獨立測試。

