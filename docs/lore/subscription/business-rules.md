---
area: subscription
kind: business-rules
---

# subscription — Business rules

<!--
Each rule is a `## heading` + a one-line meta + the body. Capture the "why" code can't show.

## Name of the rule

`code:` `path/to/file.ext` → `symbol` · `updated:` `YYYY-MM-DD` · `status:` `active`

The rule, the reasoning, and edge cases.
-->

## 方案的三種額度用的是三種不同週期

`code:` `app/Services/ChatQuotaService.php`、`app/Services/SubscriptionService.php`、`app/Http/Controllers/API/V1/SourcesController.php` · `updated:` `2026-08-14` · `status:` `active`

`plans` 上三個 limit 欄位長得很像，週期卻各自不同，看欄位名推不出來：

| 欄位 | 週期 | 執行位置 |
|---|---|---|
| `channel_limit` | 總量（帳號生命週期內的訂閱來源數） | `SourcesController::store` |
| `video_limit` | 滾動 30 天 | `SubscriptionService::syncSourceMediaToUserables` |
| `chat_limit` | **自然日**（每日重置） | `ChatQuotaService` |

三者共通的是 **`0` 代表不限制**，不是「一次都不能用」。新增額度欄位時要沿用這個約定，否則同一張表上會出現兩種相反的失效方向。

## 每日 AI 提問額度的日界是單一固定時區，不是使用者的時區

`code:` `config/ai.php` → `chat.quota_timezone` · `updated:` `2026-08-14` · `status:` `active`

額度在 `ai.chat.quota_timezone`（預設跟 `APP_TIMEZONE` 走）的 00:00 重置，全服務同一個值。

刻意不做「使用者本地時區」：`settings.data` 目前只有 `locale` 與 `ai.language`，沒有 timezone；而且讓使用者自己改時區等於給了一個免費重置額度的開關。

## 額度用量不依方案分桶——升級當場生效，降級不抹掉已用

`code:` `app/Services/ChatQuotaService.php` → `consume()` · `updated:` `2026-08-14` · `status:` `active`

`chat_usages` 的 key 是 `(user_id, quota_date)`，不含 `plan_id`。判斷方式是「**當下方案**的上限」比對「**當日總用量**」：

- 免費方案用完 3 次後當場升級 Pro（50）→ 立刻還有 47 次，付錢即時有效。
- Pro 用了 10 次後降級 Free（3）→ 當日剩餘收斂到 0，不會變負數，也不會把已用的 10 次抹掉。

如果改成依方案分桶，「升級 → 降級 → 升級」就會變成重置額度的手法。

## 一次提問失敗要不要算用掉，看的是「有沒有吐出內容」

`code:` `app/Http/Controllers/API/V1/Media/ChatController.php` → `store()` · `updated:` `2026-08-14` · `status:` `active`

扣點發生在請求進入時（驗證與 media 權限之後、建立 session 之前——否則被擋下來的請求會留下一堆只有提問、沒有回應的空 session）。串流失敗時：

- **一個 token 都沒拿到** → 退還。免費方案只有 3 次，一次上游遮斷就燒掉 1/3 額度會直接變客訴。
- **已經串出部分內容** → 算用掉。使用者實際看過回應、內容也已存進對話紀錄，上游 token 成本已經付了。

退還要用 `consume()` 當時回傳的 snapshot，不能重新算日期：串流可能跨過午夜才失敗，重算會退到隔天的額度上。

## 額度用盡回 429，不是 422

`code:` `app/Exceptions/ChatQuotaExceededException.php` · `updated:` `2026-08-14` · `status:` `active`

`channel_limit` 用盡時走 `InvalidRequestException`（422），但 chat 額度刻意另開例外回 **429** 並帶 `X-RateLimit-Limit` / `X-RateLimit-Remaining` / `X-RateLimit-Reset`（Reset 是 Unix timestamp）。理由是前端要靠這個回應決定「顯示升級引導」，比對 i18n 字串太脆弱。

成功的 200 也會帶同一組 header，讓前端不必等到被擋就能顯示「今天還剩幾次」。

**不限制的方案完全不帶這組 header**——送 `X-RateLimit-Limit: 0` 會被讀成「一次都不能問」。前端收不到 header 就代表無上限。

## 額度只有「真的發問」才扣，但用盡後周邊的 AI 功能也一起停

`code:` `app/Http/Controllers/API/V1/Media/Chat/FollowUpsController.php` → `show()` · `updated:` `2026-08-19` · `status:` `active`

`chat_limit` 的語意是「發問次數」，不是「呼叫模型的次數」。所以圍繞 chat 的周邊 AI 功能（目前是延伸問題 `GET .../follow-ups`，未來同類型的也比照）**不扣額度**——使用者只是看「可以接著問什麼」，還沒真的發問，光看建議就燒掉免費方案 3 次中的 1 次，會讓功能沒人敢用。這些端點的成本改由路由上的 throttle 中介層擋。

但反過來，**額度已經用盡時這些功能就不產生內容**：問題產出來使用者也送不出去（送出只會拿到 429），等於白付一次推論的錢。

兩者合起來的效果是：額度控制的是「使用者能問幾次」，周邊功能不佔額度、也不在沒有額度時空轉。

用盡時回的是 **200 加空陣列，不是 429**。429 是 chat 端點的職責，前端已經會在那裡顯示升級引導；周邊端點再拋一次只會讓同一件事有兩個錯誤來源，而空陣列是這些端點本來就有的「沒有素材可用」路徑。

## 方案定價的成本曝險在 chat_limit，不在轉錄

`code:` `database/seeders/PlanPriceSeeder.php`、`app/Models/Plan.php` → `chat_limit` · `updated:` `2026-08-25` · `status:` `active`

直覺會以為轉錄最貴（單價確實最高），但它是三項成本裡**最不需要擔心**的一項：

| 項目 | 單位成本 | 為什麼 |
|---|---|---|
| 轉錄 | $0.30/hr（videotranscriber.ai） | 全站去重，一支影片只付一次 |
| 摘要 | 約 $0.01/支 | 逐字稿 ≈ 12k token，走 mini |
| 一次提問 | mini $0.002 / advanced $0.02 / deep $0.04 | **每人獨立，每次都付** |

轉錄有 `media.resource_id` unique 當緩衝（見 `media/business-rules.md`），使用者愈集中在熱門與 `free` source，邊際轉錄成本愈趨近 0。提問沒有這層緩衝。

真正的放大器是 **`chat_limit` 是自然日額度**，所以承保的月上限是 `chat_limit × 30`：

| 方案 | 影片上限成本 | 提問月上限 | 提問上限成本 | 成本天花板 |
|---|---|---|---|---|
| Free | $0.45 | 90 | $0.18 | ~$0.6 |
| Pro | $3.0 | 1,500 | $30 | ~$33 |
| Advance | $7.5 | 6,000 | $240 | ~$248 |

**定價的驗收標準：成本天花板要壓在售價的 1.5 倍以內。** 極端使用者讓你小虧、可以被平均掉，而不是一個人吃掉幾十個人的毛利。2026-08 盤點時 Advance 是售價的 15 倍——只要 2% 的人接近上限就吃光全部毛利。

推論是：**價格與 cap 是同一個旋鈕，不能分開決定**。用 70% 毛利率回推（已扣金流成本，Stripe 綜合約 4.7% + $0.30/筆），每個價位能負擔的 AI 成本預算是 $5.99 → $1.22、$9.99 → $2.23、$12.99 → $2.99、$24.99 → $6.03。要調降價格就得同步砍 cap，反之亦然。

還有兩件從成本結構看得出、但排方案時容易搞錯的事：

- **高階方案不該靠提問量做區隔。** `ai_quality: deep` 的單則成本是 advanced 的 2 倍，同樣的預算只買得到差不多的提問數。高階方案的賣點應該落在 `channel_limit` / `video_limit` / `agent_enabled` 這些邊際成本低的維度。
- **年繳的金流成本明顯較低。** 每筆固定費一年只收一次，Pro 年繳的實際抽成是 4.9%、月繳是 7.0%。同一個客戶選年繳，你多拿 2 個百分點的毛利，值得在結帳頁主推。
- **低價方案的可行性隨金流商而變。** 固定費會被小額訂單放大：$5.99 在 Stripe（$0.30 固定費）是 9.7% 抽成，在 Paddle（$0.50）則是 13.4%。換金流商時要重算入門價位還撐不撐得住，這不是可以沿用的結論。

## 從 Paddle 換到 Stripe，稅務與爭議款的責任轉移到自己身上

`code:` `app/Services/StripeSubscriptionService.php`、`app/Services/StripeClient.php` · `updated:` `2026-08-25` · `status:` `active`

兩者的差別不只是費率高低，而是 **Paddle 是 Merchant of Record（MoR）、Stripe 不是**。程式碼看到的只有「換了一個 SDK」，但實際換掉的是三件事：

**1. 各國 VAT/GST 的註冊、收取與申報變成你的責任。** Paddle 當 MoR 時是它以自己的名義賣給終端使用者、替你處理完稅務；Stripe 只是收款管道。賣進歐盟／英國就要面對 20% 的 VAT。

因此定價要走**稅外加**（結帳時加上去），不能沿用 Paddle 時代稅內含的思維——$12.99 稅內含賣到歐盟，實收只有 $10.82，毛利直接少 17 個百分點。

**2. Chargeback 要自己吸收。** Stripe 每筆爭議收 $15 且款項退還客戶。消費型訂閱的爭議率約 0.3~0.5%，在 $12.99 的價位上一筆爭議等於吃掉兩個訂閱月的毛利。抓毛利目標時要另外預留約 1% 的營收。

**3. 金流成本要疊著算，不是單一費率。** Stripe 的 2.9% + $0.30 只是基本卡片費，實務上還要加 Stripe Billing 0.5%、Stripe Tax 0.5%、國際卡 1.5%（按客戶組成加權）。以一半國際客戶估，綜合約 **4.7% + $0.30**。拿 2.9% 去算毛利會高估。

`Price` 與 `Plan` 上 Paddle 與 Stripe 的關聯是並存的（`paddle()` / `stripe()` 兩個 polymorphic relation 都還在），所以 schema 看不出目前主用哪一家——以 Stripe 為準。
