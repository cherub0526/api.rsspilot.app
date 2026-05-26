## Why

現有 `POST /v1/media/{mediaId}/chat` 端點採同步方式呼叫 AI，使用者需等待完整回應才能看到結果（通常 5–15 秒）。改為 SSE 串流後，AI 產生的每個 token 會即時推送，大幅改善使用者體感回應速度。

## What Changes

- 新增 `POST /v1/media/{mediaId}/chat/stream` SSE 串流端點
  - 回應格式：`text/event-stream`，每個 token 一則 `data:` 事件，結束以 `data: [DONE]` 標示
  - 存取控制與現有 `store()` 相同：來源 free 或使用者已訂閱，否則 404
- 新增 `Completion::streamCompletions()` — 對 OpenRouter 發送 `stream: true` 請求，回傳可逐行讀取的串流
- 新增 `TemplateCompletionManager::completeStream()` — 封裝串流呼叫，回傳 Generator
- 新增路由 `POST /{mediaId}/chat/stream`

## Capabilities

### New Capabilities

- `chat-sse-stream`：透過 SSE 串流即時回傳 AI 助手的逐 token 回應

### Modified Capabilities

<!-- 無既有規格需求異動 -->

## Impact

- **新增**：`app/Http/Controllers/API/V1/Media/ChatController.php` — 加入 `stream()` 方法
- **新增**：`app/Utils/AI/Completion.php` — 加入 `streamCompletions()` 方法
- **新增**：`app/Services/Prompts/TemplateCompletionManager.php` — 加入 `completeStream()` 方法
- **修改**：`routes/v1.php` — 在 chat group 新增 stream 路由
- **修改**：`public/openapi.json` — 重新產生，加入 SSE 端點文件
