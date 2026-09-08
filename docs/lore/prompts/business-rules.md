---
area: prompts
kind: business-rules
---

# prompts — Business rules

## AI 回應語言取自使用者設定，缺漏時退回 en

`code:` `app/Models/User.php` → `aiLanguageName` · `code:` `app/Http/Controllers/API/V1/SettingsController.php` → `update` · `updated:` `2026-08-14` · `status:` `active`

chat 與 customPrompt 的回應語言來自 `settings.data.ai.language`，但這個值有兩層都可能缺：

1. **`settings` 資料列是 lazy 建立的** —— 要等使用者第一次呼叫 `PATCH /v1/settings`
   才會經由 `firstOrCreate()` 產生。從註冊到第一次改設定之間，帳號完全沒有這筆資料。
2. **有資料列也不保證有 `ai.language`** —— `firstOrCreate()` 的預設值是 `['data' => []]`。

所以任何讀這個值的地方都必須容忍缺漏，缺漏時退回 `User::DEFAULT_AI_LANGUAGE`（`en`，
對齊 `config('app.locale')` 的預設值）。在這個專案裡「沒容忍」的代價是 500 而不是空字串，
原因見 [framework/pitfalls.md](../framework/pitfalls.md) 的〈讀 null 的屬性不是 warning，是 500〉。

另外 `ISO6391::getNameByCode()` 是 `array_search()`，查不到會回傳 `false` 而不是 null —
直接內插進 prompt 會變成空字串，讓「你必須用 X 語言回答」的指示變成「你必須用 語言回答」，
模型就會自由發揮。`aiLanguageName()` 與 `SmartSummaryTemplate::languageName()` 都改用代碼本身
頂替。實務上 `SettingValidator` 已把可存入的值限制在 `ISO6391::LANGUAGES` 內，所以會踩到
`false` 的只有程式自己給的預設值。

## 方案覆寫用途，但共用產物只吃用途

`code:` `app/Utils/AI/RoutingProfile.php` · `code:` `app/Models/Plan.php` → `aiRouting` · `updated:` `2026-09-09` · `status:` `active`

一次推論用哪個模型、帶哪些路由參數，解析順序是兩層：

1. **方案** —— `plans.ai_routing`（JSON，null 表示不覆寫）
2. **用途** —— `configs.openrouter_models` / `configs.openrouter_routing`

但**不是每條路徑都吃方案**。判準是「這個產物屬於誰」：

| 路徑 | 產物歸屬 | 吃哪一層 |
|---|---|---|
| chat、延伸問題、自訂摘要試跑 | per-user | **方案** |
| 摘要、心智圖 | 全站共用一列 | **用途** |

`summaries.user_id` 與 `mindmaps.user_id` 都是 null，一支影片只產一次給所有人讀。用觸發者的方案會產生兩個都不能接受的結果：Free 使用者先打開，之後所有付費使用者永遠讀到那份便宜摘要且無法自救；反過來 Advance 使用者付高價產的，之後所有 Free 使用者免費撿。而且摘要是 queue job 產的，**根本沒有「當前使用者」可言**。

所以方案的差異只放在 per-user 的路徑上。這也是為什麼 `ChatStreamerInterface::stream()` 的 `$user` 是選填——心智圖那個呼叫端刻意傳 null。

### 三個方案的設定

| 方案 | model | cost_tier | max_price |
|---|---|---|---|
| Free | `openrouter/free` | 不適用 | 不適用（不計費） |
| Pro | `openrouter/auto` | `low` | `{prompt: 0.5, completion: 2}` |
| Advance | `openrouter/auto` | `medium` | `{prompt: 1.5, completion: 5}` |

用途層：摘要 `medium`，其餘 `low`。**摘要刻意比 chat 高一階**——它一支影片只付一次、全站攤提，而且是 chat 與心智圖的輸入素材（`MindmapController::buildInput()`、`ChatController` 的參考資料）。摘要爛掉，付費使用者的 chat 也跟著爛，而他們的方案救不了他們。

### 為什麼不用 `ai_quality`

`plans.ai_quality`（`pro` / `advanced` / `deep`）看起來就是這件事，但它是**定價頁的行銷文案**——桌面端 `PricingTable.vue` 拿它 switch 成三個標籤，而且型別在前端寫死。兩者刻意分開：調成本不該被迫改文案，改文案也不該動成本。`advanced_model_enabled` 同理。

### 使用者自選模型時，方案設定完全不套用

`custom_prompts.model_id` 讓訂閱使用者自選模型（授權走 `ResolvesUserPlan::allowedModelId()` → `plan_ai_models`），這條路**已經在運作**。自選時 `TemplateCompletionManager` 只取模型、不帶任何路由參數：使用者明講了 `claude-opus-5`，再附一個 `cost_tier: low` 的 plugin 是自相矛盾的指示，而 `max_price` 有機會把他自己選的模型擋掉。判準是 `$model !== ''`。
