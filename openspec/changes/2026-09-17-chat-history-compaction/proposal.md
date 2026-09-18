## Status

**未核准，尚未排期。** 這份 change 是把 2026-09-17 的評估結論存下來，等有實際用量數字之後再決定要不要做。三階段規劃中的**階段 1（歷史改由 server 端重建）已於同日實作完成**，不在這份 change 的範圍內——它是安全性修正，也是這裡任何方案的前提。

## Why

`POST /v1/media/{mediaId}/chat` 每一輪都要重送整段對話歷史，input token 隨輪次線性成長。20 輪的對話累計約 **135,000 input tokens**，其中絕大多數是重複送出的舊內容。

三件事讓這個成本值得檢討：

1. **歷史沒有上限。**（階段 1 已處理）原本歷史來自 client 的 `messages` 陣列，`ChatValidator` 只有 `required|array|min:1`，長度與內容都由對方決定。
2. **快取救不了全部。** OpenRouter 的 prompt cache 讀取折扣是 0.1x–0.5x，但只打折 input，而且綁模型與 provider；`openrouter/auto` 每次重新挑模型，命中要靠 `session_id` 黏著（已在送）。
3. **成本會隨方案放大。** low 帶一題約 $0.00024，medium 帶約 $0.005——Advance 一天 50 題就是 $0.25/天/人。

## What Changes

三個階段，階段 1 已完成：

| 階段 | 內容 | 狀態 |
|---|---|---|
| 1 | 歷史改由 server 依 `chat_messages` 重建，不採用 client 的 `messages` | **已完成**（2026-09-17） |
| 2 | 滑動視窗上限：只送最近 N 輪，N 放 `configs` 可線上調整 | 本 change 的建議做法 |
| 3 | **單一滾動總結**：每 N 輪（預設 5）把「既有總結 + 這 N 輪」重寫成一則新的總結，送進推論的歷史永遠是「一則總結 + 最近 N 輪原文」 | 本 change 記錄，**條件性** |

**階段 3 的形狀（2026-09-17 定案）**：總結**永遠只有一則**，不是每 N 輪各留一則。新一批輪次是併進既有總結、重寫成新的一則，所以歷史長度與對話總長度無關——這是它相對於「多則總結累積」的關鍵差別（後者 50 輪會累積約 10 則 ≈ 3,000 tokens）。

**階段 3 的觸發條件**：實際觀察到「對話長度真的會超過門檻」且「使用者確實會回頭引用早期輪次」。兩者都成立才做；否則階段 2 就是終點。

## Capabilities

### New Capabilities

- `chat-history-compaction`：限制並壓縮送進推論的對話歷史（視窗上限，必要時加上滾動總結）

### Modified Capabilities

<!-- 階段 2／3 只改 ChatController 組 prompt 的方式，不改請求或回應的形狀 -->

## Impact

- **Controller**：`App\Http\Controllers\API\V1\Media\ChatController` → `historyOf()`（階段 1 已建立的接縫，視窗與總結都掛在這裡）
- **Config**：`configs` 資料表新增視窗輪數（**不要放 `config/ai.php`**——Swoole 常駐，改 config 要重啟整個 server；理由見 `App\Utils\AI\OpenRouterModels` 的註解）
- **階段 3 另需**：`chat_sessions` 兩個新欄位（`summary`、`summary_through_message_id`）、一支非同步壓縮 job（`ShouldBeUnique` 綁 session + 樂觀鎖）、一個「更新既有總結」的 prompt 模板，以及一個決定要不要登記進 `configs.openrouter_models` 的新用途
- **不影響**：API 的請求與回應形狀、`chat_messages` 的既有資料（一列都不刪，總結是快取不是真相，前端的歷史列表照舊顯示完整逐輪紀錄）、每日額度規則
