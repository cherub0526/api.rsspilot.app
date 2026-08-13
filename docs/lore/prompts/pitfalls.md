---
area: prompts
kind: pitfalls
---

# prompts — Pitfalls

## complete() 的 $additionalParams 同時是 prompt 參數與 API options

`code:` `app/Services/Prompts/TemplateCompletionManager.php` → `complete` · `code:` `app/Utils/AI/Completion.php` → `completions` · `updated:` `2026-08-14` · `status:` `active`

`TemplateCompletionManager::complete($userContent, $model, $additionalParams)` 把**同一個陣列**
用在兩個互不相干的地方：

1. 傳給 `$this->template->buildMessages($userContent, $additionalParams)` 當組訊息的材料
2. `array_merge($this->getDefaultOptions(), $this->options, $additionalParams)` 當成送去
   OpenRouter 的 options

而 `Completion::completions()` 是 `array_merge(['model' => ..., 'messages' => $messages, ...], $options)`
—— `$options` 在後面。所以 `$additionalParams` 裡只要有跟 payload 頂層撞名的 key，就會**覆蓋**
掉前面辛苦組好的東西：

- `messages` → 整個訊息陣列被換成你傳的原始值，system prompt、逐字稿、使用者問題全部消失
- `model` / `max_tokens` / `temperature` / `top_p` → 同理被蓋掉

最麻煩的是它**完全沒有錯誤**：請求照送、AI 照回、只是 prompt 內容跟你以為的不一樣。

`completeStream()` 則是另一個極端 —— 它根本沒有 `$additionalParams` 參數，呼叫
`buildMessages($userContent)` 時不轉交任何東西。所以「傳 additionalParams 給 stream」不會出錯，
只是安靜地沒有作用。

**結論**：要餵給模板的東西（對話歷史等）一律走 `TemplateFactory::create()` 的模板 parameters，
不要走 `$additionalParams`。`buildMessages()` 讀歷史時是 additionalParams 優先、其次取模板
parameters，兩條路都通，但只有 parameters 那條在 stream 與 non-stream 都成立且不會撞到 options。

實例見 `git show 4b8382f`：對話歷史原本放在模板 parameters，`buildMessages()` 卻只讀
additionalParams，導致多輪對話的歷史從來沒進過 prompt。

## 逐字稿被排在對話歷史「之後」

`code:` `app/Services/Prompts/BaseTemplate.php` → `buildMessages` · `updated:` `2026-08-14` · `status:` `obsolete`

> **已不適用於 chat**（2026-08-14）：chat 的推論改走 NeuronAI 之後不再經過
> `buildMessages()`，參考資料也已移進系統提示詞，原因見下一則〈訊息必須以 user 開頭並嚴格交替〉。
> `buildMessages()` 本身仍被 `complete()` 那條路（customPrompt、summary）使用，
> 但那些模板沒有對話歷史，所以這裡描述的順序問題對它們是不存在的。以下保留原始記錄。

`buildMessages()` 的組裝順序是固定的：system → 對話歷史 → `getUserPrompt()` → `$userContent`。

也就是說 chat 送出去的訊息長這樣：

```
[system]    You are a helpful assistant...
[user]      第一句話          ← 歷史
[assistant] 第一回應          ← 歷史
[user]      [0.7] 逐字稿…     ← getUserPrompt()，參考資料
[user]      第二句話          ← 當前問題
```

參考資料（逐字稿）夾在對話歷史與當前問題**中間**，而不是緊接在 system 之後。一般 prompt
慣例會把參考資料放最前面，讓後面的對話保持連續。目前這個順序是既有行為，模型也吃得下，
但如果之後發現多輪對話的品質不如預期，這裡是第一個該懷疑的地方。

只有 chat 這條路有歷史，所以把歷史移到 `getUserPrompt()` 之後對其他模板（summary、
customPrompt、translation）是 no-op —— 真要調整，改動範圍比看起來小。

## 訊息必須以 user 開頭並嚴格交替，違反就整個請求失敗

`code:` `app/Http/Controllers/API/V1/Media/ChatController.php` → `buildMessages` · `code:` `vendor/neuron-core/neuron-ai/src/Chat/History/HistoryTrimmer.php` → `validateAlternation` · `updated:` `2026-08-14` · `status:` `active`

NeuronAI 會驗證送進去的訊息序列：**必須以 `user` 開頭，且 user / assistant 嚴格交替**。
違反時丟 `ChatHistoryException`，例如：

```
Invalid message sequence at position 1: expected role assistant, got user
```

**為什麼很難找**：執行檢查的是 `HistoryTrimmer::validateAlternation()`，呼叫路徑是
`StreamingNode` → `AbstractChatHistory::addMessage()` → `trimHistory()` → `trim()`。
「trimmer」這個名字看起來只是在裁剪過長的歷史，完全看不出它同時在做序列驗證，
所以堆疊指到 `HistoryTrimmer` 時很容易以為是 context 長度問題。

**兩個會咬人的地方：**

1. **`ChatValidator` 不驗順序** —— 規則只有 `messages.*.role` 的 `in:user,assistant,system`。
   客戶端送 `[user, user]` 或以 `assistant` 開頭都能通過驗證，然後在推論層變成 500。
   換 SDK 前直接打 OpenRouter 並不在意順序，所以這是遷移才冒出來的隱性契約。
2. **測試替身抓不到** —— chat 測試以容器綁定假的 `ChatStreamerInterface`（因為 NeuronAI
   走自建 Guzzle client，`Http::fake()` 攔不到），假的替身不做這個驗證。**全套測試綠燈
   之後，打真實請求才炸**。改動這條路徑時，務必實際發一次請求，別只信測試。

**現行對策**（`ChatController::buildMessages()`）：

- `system` 併入 `user`（系統提示詞由 streamer 的 instructions 帶入，不另開 system 角色）
- 連續同角色合併成一則，內容以空行相接
- 丟棄開頭的 `assistant`（沒有對應提問的回應，留著只會讓序列不合法）

也因為這條規則，**參考資料不能當成獨立的 user 訊息插在提問前** —— 那會造成連續兩個
user。所以摘要改由 `AssistantTemplate::getSystemPrompt()` 併進系統提示詞。要在提問前
額外塞任何內容時，先想清楚它會落在序列的哪個位置。
