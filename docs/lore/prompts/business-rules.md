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
