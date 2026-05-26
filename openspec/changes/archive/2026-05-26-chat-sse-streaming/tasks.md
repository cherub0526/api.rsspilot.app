## 1. Completion 串流支援

- [x] 1.1 在 `app/Utils/AI/Completion.php` 新增 `streamCompletions()` 方法
      — 使用 `withOptions(['stream' => true])` 發送請求，回傳 PSR-7 `ResponseInterface`
- [x] 1.2 `streamCompletions()` 設定 `timeout(120)` 避免長回應逾時

## 2. TemplateCompletionManager 串流支援

- [x] 2.1 在 `TemplateCompletionManager` 新增 `completeStream()` 方法
      — 組裝 messages 後呼叫 `Completion::streamCompletions()`，回傳 PSR-7 `ResponseInterface`

## 3. ChatController stream() 方法

- [x] 3.1 在 `ChatController` 新增 `stream()` 方法，簽名 `stream(Request $request, string $mediaId): ResponseInterface`
- [x] 3.2 實作與 `store()` 相同的驗證（`ChatValidator::setStoreRules()`）
- [x] 3.3 實作存取控制：media 的來源 free 或使用者已訂閱，否則拋 `NotFoundHttpException`
- [x] 3.4 呼叫 `response()->stream()` callback，在 callback 中：
      — 逐行讀取 OpenRouter SSE body（按 `\n` 切分 buffer）
      — 過濾 `data: ` 前綴，解析 JSON，取 `choices[0].delta.content`
      — 推送 `data: {"token":"..."}\n\n`
      — 遇 `data: [DONE]` 或 EOF 時推送 `data: [DONE]\n\n` 並結束
- [x] 3.5 加入防緩衝 headers：`Cache-Control: no-cache`、`X-Accel-Buffering: no`
- [x] 3.6 在 `stream()` 加入 OAT 註解（`#[OAT\Post(...)]`），標示 `text/event-stream` 回應

## 4. Route

- [x] 4.1 在 `routes/v1.php` 的 `chat` group 新增 `POST /stream` 路由，指向 `ChatController@stream`，套用 `auth` middleware

## 5. OpenAPI

- [x] 5.1 重新產生 `public/openapi.json`，確認 `POST /v1/media/{mediaId}/chat/stream` 出現

## 6. Tests

- [x] 6.1 撰寫功能測試：已訂閱使用者可取得串流回應（200 + `text/event-stream`）
- [x] 6.2 撰寫功能測試：free 來源的 media 可串流（200）
- [x] 6.3 撰寫功能測試：不可存取來源回傳 404
- [x] 6.4 撰寫功能測試：未驗證請求回傳 401
- [x] 6.5 撰寫功能測試：缺少 messages 欄位回傳 422
