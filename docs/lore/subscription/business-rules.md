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
