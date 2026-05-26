## ADDED Requirements

### Requirement: SSE 串流 chat 端點
系統 SHALL 提供 `POST /v1/media/{mediaId}/chat/stream` 端點，以 SSE 格式即時串流 AI 助手的回應。

#### Scenario: 已驗證使用者對可存取來源進行串流 chat
- **WHEN** 已驗證使用者發送含有效 `messages` 的 `POST /v1/media/{mediaId}/chat/stream`，且 media 的來源為 free 或使用者已訂閱
- **THEN** 系統回傳 HTTP 200，`Content-Type: text/event-stream`，串流推送一個或多個 `data: {"token":"..."}` 事件

#### Scenario: 串流結束訊號
- **WHEN** AI 回應全部 token 均已送出
- **THEN** 系統推送 `data: [DONE]` 事件後結束串流

#### Scenario: 來源不可存取
- **WHEN** 已驗證使用者發送請求，但 media 的來源既非 free 也未被訂閱
- **THEN** 系統回傳 HTTP 404，不開啟串流

#### Scenario: 未驗證請求
- **WHEN** 請求未帶有效 JWT token
- **THEN** 系統回傳 HTTP 401，不開啟串流

#### Scenario: 缺少 messages 欄位
- **WHEN** 請求 body 未包含 `messages` 欄位或格式不符
- **THEN** 系統回傳 HTTP 422，不開啟串流

### Requirement: SSE 事件格式
系統 SHALL 以標準 SSE 格式推送每個 token。

#### Scenario: token 事件格式
- **WHEN** AI 產生一個 token
- **THEN** 系統推送格式為 `data: {"token":"<內容>"}\n\n` 的 SSE 事件

#### Scenario: 結束事件格式
- **WHEN** AI 回應完畢
- **THEN** 系統推送 `data: [DONE]\n\n` 並結束連線

### Requirement: 串流 chat 的存取控制
系統 SHALL 套用與同步 chat 端點相同的來源存取控制規則。

#### Scenario: free 來源的 media 可直接串流
- **WHEN** media 所屬來源的 `free = true`
- **THEN** 任何已驗證使用者均可存取串流端點（200）

#### Scenario: 已訂閱來源的 media 可串流
- **WHEN** 已驗證使用者已訂閱該 media 所屬來源
- **THEN** 使用者可存取串流端點（200）

### Requirement: 必要回應 Header
系統 SHALL 在串流回應中包含防止代理緩衝的 header。

#### Scenario: anti-buffering headers 存在
- **WHEN** 成功開啟 SSE 串流
- **THEN** 回應包含 `Cache-Control: no-cache` 及 `X-Accel-Buffering: no`
