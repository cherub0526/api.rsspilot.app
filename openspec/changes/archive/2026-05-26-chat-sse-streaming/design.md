## Context

現有 chat 端點 (`POST /v1/media/{mediaId}/chat`) 透過 `TemplateCompletionManager::complete()` 同步呼叫 OpenRouter API，等待完整回應後才回傳 JSON。這會導致前端長達 5–15 秒的空白等待。

OpenRouter（OpenAI 相容）支援 `stream: true` 參數，啟用後 API 會以 SSE 格式逐 token 回傳。Hypervel 的 `response()->stream()` 原生支援 Swoole chunked transfer，兩者可直接銜接。

**關鍵元件：**
- `Completion` (`app/Utils/AI/Completion.php`) — HTTP client 包裝器，目前只有同步 `completions()`
- `TemplateCompletionManager` — 組裝 messages 並呼叫 `Completion`
- `ChatController` — 接收請求、驗證、呼叫 AI、回傳回應
- Hypervel `response()->stream()` — 回傳 `text/event-stream`，透過 `StreamOutput::write()` 推送 chunk

## Goals / Non-Goals

**Goals:**
- 新增 `POST /v1/media/{mediaId}/chat/stream` SSE 端點
- OpenRouter 串流回應逐 token 即時推送至客戶端
- 與現有同步端點共用相同的 prompt 建構邏輯（`AssistantTemplate`）
- 相同的存取控制：來源 free 或使用者已訂閱

**Non-Goals:**
- 移除或修改現有同步端點（保持向後相容）
- 持久化 chat 訊息至資料庫
- 支援多輪對話歷史（與現有 store() 行為一致）

## Decisions

### 1. 新增端點，不修改既有端點

**決策**：新增 `POST /v1/media/{mediaId}/chat/stream`，保留 `store()`。

**理由**：`store()` 為同步端點，部分客戶端（Webhook 整合、後端呼叫）仍需要。修改現有端點屬 breaking change。

### 2. `Completion::streamCompletions()` 使用 `withOptions(['stream' => true])`

**決策**：在 Guzzle 層啟用串流，回傳 `ResponseInterface`（PSR-7），body 為可逐行讀取的 stream。

**理由**：Hypervel 的 HTTP client 基於 Guzzle，`withOptions(['stream' => true])` 是標準做法；Swoole 的 `SWOOLE_HOOK_ALL` 使 Guzzle 的 socket read 為 coroutine-aware，不會阻塞 event loop。

**替代考慮**：直接用 cURL callback — 程式碼更複雜，且 Swoole 已 hook cURL，無額外優勢。

### 3. 在 `response()->stream()` callback 內以逐行讀取解析 SSE

**決策**：在 `ChatController::stream()` 的 callback 中直接讀取 OpenRouter 的 SSE body，解析 `data:` 行，轉發至 `StreamOutput`。

**資料流：**
```
OpenRouter SSE body
  → 逐行讀取（按 \n 切分）
  → 過濾 "data: " 前綴
  → 解析 JSON，取 choices[0].delta.content
  → StreamOutput::write("data: {\"token\":\"...\"}\n\n")
  → Swoole chunk → 客戶端
```

**結束條件**：遇到 `data: [DONE]` 或 stream EOF 時，送出 `data: [DONE]\n\n` 後結束 callback。

### 4. `TemplateCompletionManager::completeStream()` 回傳 PSR-7 ResponseInterface

**決策**：`completeStream()` 回傳 Guzzle/PSR-7 的 `ResponseInterface`（含可讀 stream body），由 controller 負責消費。

**理由**：保持 `TemplateCompletionManager` 薄層，不引入 Generator 等複雜抽象；Controller 已是適合處理 HTTP streaming 的地方。

## Risks / Trade-offs

- **OpenRouter 連線逾時** → 在 `streamCompletions()` 設定 `timeout(120)`，並在讀取迴圈中偵測斷線（`$body->eof()`）
- **客戶端提前斷線** → `StreamOutput::write()` 在 Swoole socket 關閉時回傳 `false`，迴圈應檢查回傳值並跳出
- **Nginx 反代緩衝** → Header 加入 `X-Accel-Buffering: no`，避免 Nginx 緩衝吞掉 SSE chunk
- **OpenRouter 錯誤回應（非 200）** → 在進入 stream 前檢查 HTTP status，改拋 `NotFoundHttpException` 或自訂 SSE error event
