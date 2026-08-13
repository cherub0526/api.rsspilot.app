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

`code:` `app/Services/Prompts/BaseTemplate.php` → `buildMessages` · `updated:` `2026-08-14` · `status:` `active`

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
