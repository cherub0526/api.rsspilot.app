---
area: prompts
kind: business-rules
---

# prompts — Business rules

## 思考過程是分開的一條流，而且只有對話那條路要它

`code:` `app/Utils/AI/OpenRouterProvider.php` → `processContentDelta()`、`app/Utils/AI/ChatChunk.php` · `updated:` `2026-09-21` · `status:` `active`

會思考的模型在回答之前會先產生一段推理內容。**它跟回答是上游分開送的兩個欄位**
（OpenRouter 串流時是 `delta.reasoning` 與 `delta.content`），從頭到尾都不能合流：

| 合流的後果 | 發生在哪 |
|---|---|
| 思考過程被印進對話氣泡 | 前端只認一種 SSE payload 時 |
| 下一輪模型把自己的自言自語當成說過的話 | `chat_messages.content` 混入推理時 |
| 心智圖的 markdown 夾雜推理 | MindmapController 不濾 chunk 時 |

所以串流的元素是 `ChatChunk`（帶 type 的值物件）而不是字串，`chat_messages.parts`
裡推理是獨立的 `thinking` 片段，`content` 永遠只是 **text 片段**的投影。

要讓推理內容真的流出來，四個環節缺一不可——少任何一個，畫面上就是什麼都沒有，
而且**不會報錯**：

1. 請求要帶 `reasoning: {effort, exclude: false}`（`NeuronChatStreamer::parametersFor()`）。
   不帶的話多數模型根本不回推理內容
2. `OpenRouterProvider::processContentDelta()` 要覆寫。NeuronAI 的 OpenAI 版只讀
   `delta.content`，推理在這一層就會被丟掉
3. `NeuronChatStreamer` 要把 `ReasoningChunk` 轉成 `ChatChunk::reasoning()`，而不是
   只挑 `TextChunk`
4. `ChatController` 要發 `ChatReasoningEvent`，`StreamController` 要把它當成另一種
   SSE payload 送出去

思考力度是**每個方案各自的成本設定**，旋鈕在 `plans.ai_routing`（跟 cost_tier、
max_price 同一處，改資料不必部署），沒指定時才吃 `ai.chat.reasoning_effort` 的預設
值。目前只有 Advance 設成 `medium`，其餘吃預設的 `low`。

**刻意不用 `ai_quality` 當判準**——那一欄（pro / advanced / deep）是定價頁的行銷
文案，與實際成本刻意不綁定，理由見 `Plan::aiRouting()` 的註解：調成本不該被迫改文案，
改文案也不該動成本。

`withReasoning` 預設 false，而且分兩道判斷：

- **哪條路**：只有 chat 會開。心智圖與摘要沒有地方顯示思考過程，開了就是純粹多付錢
- **哪個方案**：`plans.thinking_enabled`（目前只有 Advance）。其餘方案與沒有方案的
  人一律關掉——推理 token 按 output 計價，而每日提問額度承保的月上限是
  `chat_limit × 30`（見
  [subscription/business-rules.md](../subscription/business-rules.md)〈方案定價的成本曝險〉），
  免費方案的成本天花板本來就只有約 $0.6/月

**三個欄位各司其職，不要互相取代**：

| 問題 | 欄位 | 性質 |
|---|---|---|
| 能不能思考 | `plans.thinking_enabled` | 權益（定價頁讀它） |
| 能不能上網查 | `plans.agent_enabled` | 權益（定價頁讀它） |
| 想多久 | `plans.ai_routing.reasoning.effort` | 成本設定 |

兩個權益目前的值剛好一樣（只有 Advance 開），但**刻意分成兩欄**：它們是兩個能賣的
東西，哪天想讓 Pro 只有思考、沒有搜尋，改資料就好，不必回來動程式。

`ai_quality`（pro / advanced / deep）不在這張表裡——它是定價頁的行銷文案，與成本和
權益都刻意不綁定（見 `Plan::aiRouting()` 的註解）。

額度的退還判準看的是**回答**而不是推理：只吐了思考過程就斷掉的話，使用者拿到的是
一段沒有結論的獨白，那一次要退。上游確實已經收了推理的錢，但那是我們選擇開
reasoning 的代價，不該轉嫁到使用者的每日額度上。

## 上網查資料是方案權益，而且成本結構跟提問次數不一樣

`code:` `app/Utils/AI/NeuronChatStreamer.php` → `webSearchParameters()`、`app/Http/Controllers/API/V1/Media/ChatController.php` · `updated:` `2026-09-22` · `status:` `active`

對話可以讓模型自己上網查，但**只開給 `plans.agent_enabled` 的方案**（目前只有
Advance）。判準用資料不用方案名稱，與 `custom_summary_enabled` /
`download_enabled` / `screenshot_enabled` 同一套做法。

沒開通的人**不會被擋下請求**——這是一個能力，不是一道閘門。他照常對話，只是模型
答不出摘要以外的東西時只能說不知道。所以關閉時是「不送 `tools`」而不是拋例外。

### 走 OpenRouter 的 server tool，不是自己掛搜尋工具

2026-09-22 之前是 Tavily 的 client-side 工具（NeuronAI 的 `TavilySearchTool`），
現在送的是 OpenRouter 的 `openrouter:web_search` **server tool**：搜尋在 OpenRouter
那一側跑完才把結果交回模型，client 完全不參與，也不需要任何搜尋供應商的 key。

`webSearchParameters()` 回的是 **request body 的兩個頂層欄位**（`tools` 與
`max_tool_calls`），不是 NeuronAI 的工具物件。**不能改用 `$agent->addTool()`**，
兩個原因都會安靜地壞掉：

1. NeuronAI 的 `HandleStream` 是 `$body = [..., ...$this->parameters]`，之後才在
   `$this->tools` 非空時寫入 `$body['tools']`。只要掛了任何一個 NeuronAI 工具，
   我們從 parameters 送的 tools 就會被**整個覆蓋掉**——不報錯，畫面上只是沒有
   搜尋。這也是為什麼 Tavily 必須移除而不是並存。
2. NeuronAI 自己的 `ProviderTool` 抽象在這條路上是死的：
   `Providers/OpenAI/ToolMapper.php` 對 `ProviderToolInterface` 直接拋
   「OpenAI completions API does not support built-in Tools」。

### 不要改用 `plugins: [{id: "web"}]`

OpenRouter 有兩個長得很像的東西，差別在**誰決定要不要搜**：

| | `plugins: [{id:"web"}]` | `openrouter:web_search` server tool |
|---|---|---|
| 觸發 | 每次請求都搜 | 模型自己決定，0..N 次 |
| 實際觸發率 | 100% | 約三成題目 |

兩個都支援 `openrouter/auto`。但實際會需要搜尋的題目大約三成，換成 plugin 等於把
這一項的成本乘上三倍——Advance 重度使用者的月成本會從 1.70x 變成 2.56x 售價。

### 三個旋鈕都是成本決定

`config/ai.php` 的 `ai.chat.web_search`，單位成本以 Advance 重度使用者（每天 50 題、
三成觸發搜尋）的月成本計：

| 旋鈕 | 值 | 為什麼 |
|---|---|---|
| `engine` / `mode` | `parallel` / `turbo` | 約 $0.001/次；不指定時上游走 Exa 的 $0.007。這一項從 $3.15 降到 $0.45 |
| `max_results` | 3 | 每筆結果約 2,000–4,000 字元，整段變成 input token |
| `max_tool_calls` | 2 | **上游預設是 30**，不壓住的話單次對話可以搜 30 輪 |

`max_tool_calls` 是這三個裡最危險的一個，理由跟舊版的 `max_runs` 一樣、而且更嚴重：
每多一次工具步驟，模型就要把摘要、歷史與**已累積的搜尋結果**整個重跑一遍。貴的是
這個，不是搜尋本身那幾毫分——拆開來看，重跑脈絡是 $6.04/月、搜尋費只有 $0.45。

自架 SearXNG 評估過，結論是不划算：邊際成本雖然是 0，但 Railway 上 $16/月的固定
成本要 36 位重度 Advance 使用者才打平 parallel turbo，而且資料中心 IP 會被上游搜尋
引擎擋、回傳的是 snippet 不是抽取內容、壞掉時安靜回空結果——那是付費權益上最糟的
失敗模式。完整試算見 rsspilot.app repo 的 `docs/pricing-cost-model.md`。

### 權益與成本設定仍然分開

`plans.agent_enabled` 決定**能不能用**（定價頁讀它），`ai.chat.web_search` 決定
**怎麼搜**。`webSearchParameters()` 疊在 `plans.ai_routing` 之上而不是寫進它，就是
為了維持這條界線——`ai_routing` 是成本設定，不該變成第二個決定權益的地方，否則
定價頁讀的欄位跟實際生效的開關會變成兩個真相來源。

（原本這裡記的 Tavily 地雷——`withOptions()` 是整組覆蓋、`include_answer` 不能省
——隨著 Tavily 移除一起失效了。`ChatChunk` 的型別名稱與 `chat_messages.parts` 不同
那一條仍然成立，見〈思考過程是分開的一條流〉。）

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

`code:` `app/Utils/AI/RoutingProfile.php` · `code:` `app/Models/Plan.php` → `aiRouting` · `updated:` `2026-09-16` · `status:` `active`

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
| Free | `openrouter/auto` | `low` | `{prompt: 0.5, completion: 2}` |
| Pro | `openrouter/auto` | `low` | `{prompt: 0.5, completion: 2}` |
| Advance | `openrouter/auto` | `medium` | `{prompt: 1.5, completion: 5}` |

**Free 與 Pro 目前的路由完全相同**，這是 2026-09-16 撤掉 `openrouter/free` 的結果（原因見下一條）。也就是說**兩者的差異現在完全在額度與功能上，不在模型品質上**：Free 是 `chat_limit` 3、`video_limit` 3，Pro 是 20。要重新拉開模型層的差距，改 Free 的 `cost_tier` 或 `max_price` 即可——但先想清楚 `ai_quality`（定價頁文案）寫的是什麼，兩邊不同步就是對使用者說謊。

用途層：摘要 `medium`，其餘 `low`。**摘要刻意比 chat 高一階**——它一支影片只付一次、全站攤提，而且是 chat 與心智圖的輸入素材（`MindmapController::buildInput()`、`ChatController` 的參考資料）。摘要爛掉，付費使用者的 chat 也跟著爛，而他們的方案救不了他們。

### 為什麼不用 `ai_quality`

`plans.ai_quality`（`pro` / `advanced` / `deep`）看起來就是這件事，但它是**定價頁的行銷文案**——桌面端 `PricingTable.vue` 拿它 switch 成三個標籤，而且型別在前端寫死。兩者刻意分開：調成本不該被迫改文案，改文案也不該動成本。`advanced_model_enabled` 同理。

### 使用者自選模型時，方案設定完全不套用

`custom_prompts.model_id` 讓訂閱使用者自選模型（授權走 `ResolvesUserPlan::allowedModelId()` → `plan_ai_models`），這條路**已經在運作**。自選時 `TemplateCompletionManager` 只取模型、不帶任何路由參數：使用者明講了 `claude-opus-5`，再附一個 `cost_tier: low` 的 plugin 是自相矛盾的指示，而 `max_price` 有機會把他自己選的模型擋掉。判準是 `$model !== ''`。

## 專案不用免費模型，因為它的限流是帳號共用的

`code:` `database/migrations/2026_09_16_100000_drop_openrouter_free_routing.php` · `code:` `app/Jobs/Media/SummaryTranslationJob.php` · `updated:` `2026-09-16` · `status:` `active`

2026-09-16 起，專案裡**沒有任何路徑走 `openrouter/free`**。撤掉的有兩處：Free 方案的
`plans.ai_routing`（chat、延伸問題、自訂摘要試跑），以及摘要翻譯這個用途。兩者都改成
Auto Router 的 `low` 帶。

放棄的理由不是品質，是**限流的形狀**（數字見 pitfalls〈免費模型的限流是「整個帳號」共用的〉）：

1. **20 RPM / 1000 RPD 是整個帳號共用的**，不是每把 key、每個用途各一份。每分鐘那道
   還不能靠買 credits 提高。所以「Free 使用者的互動」與「背景批次翻譯」是在同一個桶子
   裡互相排擠——而背景批次的量是隨影片數長的，使用者互動則是隨行銷活動跳的，兩者誰把
   誰餓死完全不受控。
2. **撞牆的後果是計費，不是停下來。**`RoutedInference` 在方案這層失敗時會退回用途層，
   而用途層是付費的 Auto Router，只留一行 `Log::warning`。於是「省錢的設定」在用量上來
   的那一天會自己變成「花錢的設定」，而且帳單出來之前沒有人會發現。

這就是判準：**一個會靜默退到付費路徑的免費方案，等於把成本風險藏起來，不是省錢。**
省錢要省在看得見的地方——`cost_tier` 與 `provider.max_price` 都是明碼標價、算得出天花板
的東西（`low` 帶實測約 $0.065 / $0.18 每百萬 token，翻譯一份摘要只有千把 token）。

要再走一次免費路線之前，先回答這三題：誰跟誰共用那 1000？撞牆時退去哪裡？那個退路會不會
計費？三題有一題答不出來就不要做。

## 帶圖提問有兩層上限，整個請求只送最新的 4 張

`code:` `app/Http/Controllers/API/V1/Media/ChatController.php` → `collectImages()` · `updated:` `2026-09-13` · `status:` `active`

- **每則訊息 4 張**（`ChatValidator` 的 `messages.*.images` → `max:4`）—— 條目是 `{second, checksum}`，識別畫面的是 checksum
- **整個請求 4 張**（`IMAGES_PER_REQUEST`），由新到舊取，同一則訊息內也是由新到舊

第二層才是重點。前端會把完整歷史送回來，裡頭每一則提問都帶著當時附的截圖（`{second, checksum}`）；照單全收的話，對話愈長、每一輪要重付的圖片 token 就愈多，而圖片 token 遠貴於文字。

取最新的而不是直接丟掉歷史圖片，是為了讓「剛剛那張圖的旁邊那欄呢」這種接續追問仍然成立。被擠掉的截圖只留下它們當時的文字，AI 上一輪對那張圖的描述本來就在歷史裡。

## 帶圖提問扣 2 點，而且剩餘不足時整個擋下來

`code:` `app/Http/Controllers/API/V1/Media/ChatController.php` → `QUOTA_COST_WITH_IMAGES` · `updated:` `2026-09-13` · `status:` `active`

一次帶圖提問扣 2 點每日額度，純文字仍是 1 點。vision 推論的單次成本明顯高於純文字，扣一樣的點數等於讓帶圖的人用同樣的額度買到更貴的東西。

**不做部分扣點。** `DailyQuotaService::consume()` 的判斷是 `used + cost > limit`（不是 `used >= limit`），所以剩 1 點的使用者附了圖會直接拿到 429 —— 剩的點數不夠買這一次，就整個不賣。`cost` 為 1 時兩個判斷等價，純文字的行為完全沒變。

退還也必須是 2 點。`DailyQuotaSnapshot` 因此多帶一個 `cost` 欄位，`release()` 讀它而不是讓呼叫端再傳一次 —— 理由與 `quotaDate` 相同：扣了 2 點只退 1 點跟退到隔天的額度上一樣是錯的，而讓兩邊各自記著就會有對不起來的一天。

前端在附圖時會在剩餘次數旁標明「這則扣 2 次」，並在剩餘不足時轉成警示色。不標的話「剩餘 1 次」看起來還能問，送出才被擋。

（原本這裡記的是「額度沒有為帶圖加權，是個定價缺口」。缺口已經補上，但〈心智圖的成本沒有進定價計算〉那一條仍然成立。）

## 圖片排在文字前面

`code:` `app/Utils/AI/NeuronChatStreamer.php` → `toContent()` · `updated:` `2026-09-13` · `status:` `active`

送進推論、落庫成 `parts`、前端氣泡渲染，三處的順序一致：截圖在前、文字在後。

提問幾乎都在指涉圖片（「這一格在講什麼」「比較這兩格」），先給畫面再給問題，指涉對象才會在問題出現之前就已經進入脈絡。反過來排，模型讀到問題時還不知道「這一格」是什麼。

## 送進推論的歷史有視窗上限，因為成本是隨輪數平方成長的

`code:` `app/Http/Controllers/API/V1/Media/ChatController.php` → `historyOf()`、`config/ai.php` → `chat.history_window` · `updated:` `2026-09-22` · `status:` `active`

OpenRouter 不替我們保存對話，所以每一輪都要把系統提示、摘要與**整段歷史**重送一遍。
同一段 session 裡第 i 題的 input 是 i 的線性函數，累積下來就是平方成長：

| 50 題/天怎麼分配 | 對話月成本（Advance） |
|---|---|
| 分成 5 段，每段 10 題 | $36.50 |
| 全部集中在 1 段 | $94.10 |

同樣的題數、同樣的模型，差別只在對話有沒有分段——**而分段與否完全由使用者決定**，
我們這一側唯一能控制的就是視窗。`ai.chat.history_window` 目前是 20 則（約 10 輪），
`0` 代表不設限。

三件實作上要記住的事：

- **倒著查再翻回來**，不是查出全部再切。session 可以有上千則訊息，`limit` 讓資料庫
  只回我們要的那幾列；先 `get()` 再 `slice()` 等於把省下的 token 成本換成記憶體。
- **倒查時兩個排序都要一起反向。** 同一輪的提問與回應常落在同一秒，`created_at`
  之外還要拿 ULID 當 tiebreaker，只反向其中一個會讓同秒內的先後錯亂。
- **截斷是從舊的那端砍，所以歷史可能以一則 assistant 訊息開頭。** OpenAI 相容的 API
  接受這種開頭，不必為了湊成對再多砍一則。

視窗失效不會報錯，只會在帳單上出現，所以 `ChatControllerTest` 有兩支測試釘住它
（視窗生效、以及 `0` 時回到不設限）。完整試算見 rsspilot.app repo 的
`docs/pricing-cost-model.md`。
