# Chat Session 設計文件

**日期：** 2026-05-29
**範圍：** `app/Http/Controllers/API/V1/Media/ChatController.php` 及相關檔案

---

## 背景

現有 `POST /media/{mediaId}/chat` 每次呼叫都是無狀態的，AI token 透過 SSE streaming 送出，但 user message 與 AI response 均未保存。DB 已有 `chat_sessions` 與 `chat_messages` 兩張表備用。

## 目標

1. 每次對話自動建立或延續 ChatSession
2. 保存 user message 與完整 AI response（token 累積後入庫）
3. 提供 API 讓前端列出 session 清單與查詢歷史訊息

---

## API 設計

### 修改既有端點

#### `POST /v1/media/{mediaId}/chat`

**Request body 新增 optional 欄位：**

```json
{
  "session_id": "01JC...",   // 可選，不傳則自動建立新 session
  "messages": [
    { "role": "user", "content": "這部影片在說什麼？" }
  ]
}
```

**行為：**
- `session_id` 未傳 → 建立新 ChatSession，title 取第一則 user message 前 50 字
- `session_id` 已傳 → 查找並驗證該 session 屬於此 user + media，否則 404
- 儲存 user message（`role = user`）
- 串流 AI 回覆並累積 token buffer
- 串流結束（`[DONE]`）後將完整 AI response 存為 ChatMessage（`role = ai`）
- Response body 新增 `session_id`：

```json
{ "status": "done", "session_id": "01JC..." }
```

### 新增端點

#### `GET /v1/media/{mediaId}/chat/sessions`

列出此 user 在此 media 下的所有 session，依 `updated_at` 倒序分頁。

**Response：**
```json
{
  "data": [
    {
      "id": "01JC...",
      "title": "這部影片在說什麼？",
      "created_at": "2026-05-29T10:00:00Z",
      "updated_at": "2026-05-29T10:05:00Z"
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

#### `GET /v1/media/{mediaId}/chat/sessions/{sessionId}`

取得單一 session 及其完整對話紀錄。

**Response：**
```json
{
  "id": "01JC...",
  "title": "這部影片在說什麼？",
  "created_at": "...",
  "messages": [
    { "id": "01JD...", "role": "user", "content": "這部影片在說什麼？", "created_at": "..." },
    { "id": "01JE...", "role": "ai",   "content": "這部影片介紹了...",  "created_at": "..." }
  ]
}
```

---

## 資料流

```
POST /chat
  │
  ├─ validate (加 optional session_id)
  ├─ resolveMedia()
  ├─ findOrCreateSession()  ← 新增
  ├─ saveUserMessage()      ← 新增
  │
  ├─ AI streaming (現有邏輯 + 累積 buffer)
  │    └─ 每個 token → Event::dispatch(ChatTokenEvent) + buffer += token
  │
  ├─ on [DONE] → saveAiMessage(buffer) + Event::dispatch(ChatDoneEvent)
  └─ on error  → Event::dispatch(ChatErrorEvent) + throw
```

---

## 元件清單

### 新增

| 檔案 | 說明 |
|---|---|
| `app/Http/Resources/ChatSessionResource.php` | Session 清單用（不含 messages） |
| `app/Http/Resources/ChatSessionDetailResource.php` | Session 詳情用（含 messages） |
| `app/Http/Resources/ChatMessageResource.php` | 單則訊息 |

### 修改

| 檔案 | 修改項目 |
|---|---|
| `ChatController.php` | `store()` 加 session 邏輯；新增 `sessions()`, `sessionShow()` |
| `ChatValidator.php` | `setStoreRules()` 加 optional `session_id` 驗證 |
| `routes/v1.php` | 新增兩條 GET 路由 |
| OpenAPI Schemas | 新增 ChatSession / ChatMessage schema |

---

## 錯誤處理

| 情境 | 行為 |
|---|---|
| 傳入的 `session_id` 不屬於此 user / media | 404 NotFoundHttpException |
| AI 串流中途錯誤 | AI message 不入庫；ChatErrorEvent 仍廣播 |
| `session_id` 格式不合法（非 ULID） | 400 InvalidRequestException |
