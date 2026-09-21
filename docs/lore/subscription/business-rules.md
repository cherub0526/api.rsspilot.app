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

## 免費月給在第一次結帳，不是註冊——而且終生只給一次

`code:` `app/Services/SubscriptionService.php` → `isEligibleForFreeMonth()`、`app/Services/PaddleSubscriptionService.php` → `applyFreeMonth()` · `updated:` `2026-09-21` · `status:` `active`

2026-09 之前是「註冊就送一個月 Pro 試用」（`UserObserver::created()` 直接建一筆
`status = trial` 的訂閱）。現在改成 **買什麼方案就送該方案一個月，時機在第一次訂閱**：

| | 舊：註冊送試用 | 新：首次訂閱免首月 |
|---|---|---|
| 贈送時機 | 註冊當下 | 第一次結帳 |
| 送什麼 | 固定 Pro 月繳 | 他自己選的那個方案與週期 |
| 新會員的訂閱紀錄 | 一筆 trial | 沒有，直接落免費方案 |
| 首次扣款日 | 試用結束日（訂閱另計） | 結帳日 + 1 個月 |

實作上分成「給」與「擋」兩半，因為兩家金流商都只支援「綁在價格上的固定試用期」，
**沒有任何一家知道「這個人是不是第一次」**：

- **給**：Paddle 是 price 上的 `trial_period`（`paddle:sync` 對每筆付費 price 設成
  1 個月，$0 的免費方案明確清成 `null`）；Stripe 是結帳時的 `subscription_data.trial_end`。
- **擋**：`applyFreeMonth()` 對沒有資格的人呼叫 `POST /subscriptions/{id}/activate`
  當場計費。**這一半不能省**——price 上的試用期對每個結帳的人都生效，少了它
  「訂閱 → 取消 → 再訂閱」就是無限續杯。

資格判定（`isEligibleForFreeMonth()`）刻意用「**曾經成立過訂閱**」而不是「目前有沒有
在訂閱」：Paddle 取消訂閱時我們自己的 `subscriptions.status` 不會被改寫（只有 Stripe
的 webhook 會寫 `canceled`），拿當下狀態判斷一定會漏。三個排除項各有原因：

- `payment_method = trial`：舊制註冊送的那批試用訂閱是系統送的，不是使用者買的，
  不吃掉他的首月免費（這批訂閱保留到期，不做資料遷移）。
- 結帳當下那筆 `paying` 紀錄：`SubscriptionsController::store()` 在導去結帳前就先建好了，
  不能讓它把自己的資格吃掉。同理，**放棄結帳留下的 `paying` 紀錄也不算用過**。
- 軟刪除的紀錄要算（`withTrashed()`）——訂閱成立過不會因為資料列被刪掉而沒發生過。

年繳也送一個月：`trial_period` 與 `billing_cycle` 在 Paddle 是分開的兩件事，所以年繳
是「先免費一個月，再扣一整年」。

免費月期間 `status` 是 `trial`、`next_date` 是首次扣款日，首次扣款成功後才轉 `active`
（`syncFromPaddle()`）。`start_date` 則一律是結帳當天——免費月是這筆訂閱的第一期，
不是它的前傳。這也表示首次扣款失敗時 `next_date` 會停在過去，`scopeActive()` 自然把
這筆訂閱排除，使用者落回免費方案，不需要額外的排程去收。

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

- 免費方案用完 3 次後當場升級 Pro（20）→ 立刻還有 17 次，付錢即時有效。
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

## 方案定價的成本曝險在對話，不在轉錄

`code:` `database/seeders/PlanPriceSeeder.php`、`app/Models/Plan.php` → `chat_limit` · `updated:` `2026-09-22` · `status:` `active`

直覺會以為轉錄最貴（單價確實最高），但整個成本結構真正的分水嶺不是單價，而是
**這筆錢會不會被攤掉**：

| 類別 | 項目 | 隨規模變便宜？ |
|---|---|---|
| 全站付一次 | 轉錄、摘要、摘要翻譯 | 會，隨該來源的訂閱者數 |
| 共用但按需產生 | 心智圖 | 會，第一個打開的人才付 |
| **每人每次都付** | **對話、延伸問題** | **不會** |

`media.resource_id` unique 讓轉錄與摘要全站只跑一次（見 `media/business-rules.md`），
而且 `videotranscriber:start` 是對每一筆 `status = created` 派工、跟有沒有使用者對應
無關——所以轉錄支出跟著 **`channel_limit` × 該頻道的發片速度**走，不是
`video_limit`。對話沒有任何一層緩衝。

真正的放大器有兩個，**而且會相乘**：

1. **`chat_limit` 是自然日額度**，所以承保的月上限是 `chat_limit × 30`。
2. **每一輪都要把整段歷史重送**，所以同一段 session 的成本是隨輪數平方成長的
   （見 `prompts/business-rules.md`〈送進推論的歷史有視窗上限〉）。

2026-09-22 盤點後的狀態（每位使用者每月，已含金流成本後的實收比較）：

| 方案 | 典型 | 重度 | 極端 | 成本÷售價（重度） |
|---|---|---|---|---|
| Free | $1.49 | $1.65 | $1.69 | — |
| Pro | $4.70 | $6.18 | $8.10 | 0.48x |
| Advance | $15.77 | $46.61 | $104.21 | 1.87x |

**定價的驗收標準：成本天花板要壓在售價的 1.5 倍以內。** 極端使用者讓你小虧、可以
被平均掉，而不是一個人吃掉幾十個人的毛利。

這個標準被破過兩次，兩次的成因一樣：**加功能時沒有回來重算**。2026-08 是
`chat_limit` 200 讓 Advance 達售價 15 倍；2026-09 是 web search、推理力度 medium
與沒有上限的歷史重送，把它推回 1.87x。修法是四個旋鈕一起動——額度 50 → 30、
歷史視窗 20 則、搜尋改走便宜引擎、`max_tool_calls` 壓到 2。

推論是：**價格與 cap 是同一個旋鈕，不能分開決定**。用 70% 毛利率回推（Paddle 5% +
$0.50、稅外加），每個價位能負擔的 AI 成本預算是 $12.99 → $3.55、$24.99 → $6.97。
要調降價格就得同步砍 cap，反之亦然。

還有幾件從成本結構看得出、但排方案時容易搞錯的事：

- **高階方案不該靠提問量做區隔。** 對話是唯一攤不掉的成本，加額度等於線性加成本。
  高階方案的賣點應該落在 `channel_limit`、`agent_enabled`、`screenshot_enabled`
  這些會被攤掉或本來就便宜的維度。
- **年繳其實賺得比月繳少。** 金流抽成確實較低（Pro 年繳 5.4%、月繳 8.8%），但 17%
  的折扣把那點優勢蓋過去了：年繳每月實收 $10.17、月繳 $11.84。年繳的價值在預收現金
  與留存，不在毛利——結帳頁主推年繳沒問題，**理由要寫對**。
- **改 seeder 的方案數值一定要附 migration。** seeder 只在建表時跑一次，正式環境的
  資料列不會因為改 seeder 而改變。這個 drift 已經發生三次（價格、Pro 與 Advance 的
  `chat_limit`），`2026_09_22_110000_reconcile_plan_chat_limits` 就是在收這筆債。
- **低價方案的可行性隨金流商而變。** 固定費會被小額訂單放大：$5.99 在 Paddle
  （$0.50 固定費）是 13.4% 抽成。換金流商時要重算入門價位還撐不撐得住。

完整試算與可重跑的模型在 rsspilot.app repo 的 `docs/pricing-cost-model.md` 與
`docs/pricing-cost-model.mjs`。


## 從 Paddle 換到 Stripe，稅務與爭議款的責任轉移到自己身上

`code:` `app/Services/StripeSubscriptionService.php`、`app/Services/StripeClient.php` · `updated:` `2026-08-25` · `status:` `active`

兩者的差別不只是費率高低，而是 **Paddle 是 Merchant of Record（MoR）、Stripe 不是**。程式碼看到的只有「換了一個 SDK」，但實際換掉的是三件事：

**1. 各國 VAT/GST 的註冊、收取與申報變成你的責任。** Paddle 當 MoR 時是它以自己的名義賣給終端使用者、替你處理完稅務；Stripe 只是收款管道。賣進歐盟／英國就要面對 20% 的 VAT。

因此定價要走**稅外加**（結帳時加上去），不能沿用 Paddle 時代稅內含的思維——$12.99 稅內含賣到歐盟，實收只有 $10.82，毛利直接少 17 個百分點。

**2. Chargeback 要自己吸收。** Stripe 每筆爭議收 $15 且款項退還客戶。消費型訂閱的爭議率約 0.3~0.5%，在 $12.99 的價位上一筆爭議等於吃掉兩個訂閱月的毛利。抓毛利目標時要另外預留約 1% 的營收。

**3. 金流成本要疊著算，不是單一費率。** Stripe 的 2.9% + $0.30 只是基本卡片費，實務上還要加 Stripe Billing 0.5%、Stripe Tax 0.5%、國際卡 1.5%（按客戶組成加權）。以一半國際客戶估，綜合約 **4.7% + $0.30**。拿 2.9% 去算毛利會高估。

`Price` 與 `Plan` 上 Paddle 與 Stripe 的關聯是並存的（`paddle()` / `stripe()` 兩個 polymorphic relation 都還在），所以 schema 看不出目前主用哪一家——以 Stripe 為準。
