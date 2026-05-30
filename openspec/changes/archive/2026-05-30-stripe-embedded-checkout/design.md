## Context

目前 Stripe 結帳流程直接在後端建立 Stripe Subscription（`payment_behavior: default_incomplete`），回傳 PaymentIntent 的 `client_secret`，前端呼叫 `stripe.confirmPayment()` 確認付款。

前端改採 `@stripe/stripe-js` Embedded Checkout 後，需要 Checkout Session 的 `client_secret` 以呼叫 `stripe.initEmbeddedCheckout()`，兩者 API 路徑不同，無法相容。

## Goals / Non-Goals

**Goals:**
- 將 Stripe 結帳改為 Checkout Session（`ui_mode: embedded`），讓前端能掛載 Embedded Checkout 表單
- 透過 `checkout.session.completed` webhook 啟用訂閱，並將 `stripes` 表的 `stripe_id` 從 Session ID 更新為 Subscription ID，確保後續 `invoice.paid`、`customer.subscription.deleted` 事件能正確對應
- 提供 `GET /subscriptions/checkout-session` 讓前端在 `return_url` 頁確認付款結果

**Non-Goals:**
- Paddle 結帳流程不受影響
- 不修改 Transaction 記錄邏輯（仍由 `invoice.paid` 處理）
- 不支援 Stripe Checkout 的 `redirect` 模式

## Decisions

### 決策一：Checkout Session 而非 Payment Element

**選擇**：Checkout Session（`ui_mode: embedded`）

**替代方案**：Payment Element（直接在前端用 `stripe.elements()` 掛載付款表單，後端仍建立 Subscription）

**理由**：Checkout Session 由 Stripe 全權處理付款頁邏輯（試用期、稅金、3DS 驗證），前端工程量顯著更低，且符合前端已選擇 `@stripe/stripe-js` Embedded Checkout 的方向。

---

### 決策二：checkout.session.completed 先更新 stripe_id，invoice.paid 再寫 Transaction

**選擇**：事件分工——`checkout.session.completed` 啟用訂閱並將 `stripe_id` 從 `cs_xxx` 換成 `sub_xxx`；`invoice.paid` 保持原職（更新 `next_date`、寫 Transaction）。

**理由**：Checkout Session 建立的訂閱，invoice 的 `metadata` 不含 `subscriptionId`（metadata 掛在 Session），必須先在 `checkout.session.completed` 更新 `stripe_id`，`invoice.paid` 才能用 `resolveSubscriptionIdFromStripeId()` 找到正確的本地訂閱。

---

### 決策三：後端驗證 session 擁有權

**選擇**：`GET /subscriptions/checkout-session` 透過 `session.metadata.subscriptionId` 比對當前 user，不屬於該 user 的 session 回 404。

**理由**：Checkout Session ID（`cs_xxx`）由前端從 URL 帶入，若不驗證擁有權，任何持有 session_id 的人都能查詢他人付款資訊。

## Risks / Trade-offs

- **[Risk]** `checkout.session.completed` 與 `invoice.paid` 皆嘗試啟用訂閱 → **Mitigation**：`handleInvoicePaid` 設 `status=active` 為冪等操作，重複執行無害；Transaction 重複建立由 `whereHas(stripe, stripe_id)` 的 exists 檢查防止。
- **[Risk]** `STRIPE_RETURN_URL` 未設定時 URL 拼接會出現 `null?session_id=...` → **Mitigation**：部署前需確認環境變數已設定；可在 service 層加驗證（目前由 ops 流程保證）。
- **[Trade-off]** Embedded Checkout 的付款完成流程依賴 Stripe 重導向與 session 狀態輪詢，比 PaymentIntent confirm 多一個網路往返 → 可接受，換來前端大幅減少付款邏輯。

## Migration Plan

1. 設定 `STRIPE_RETURN_URL` 環境變數（指向前端結帳回傳頁，如 `https://app.example.com/checkout/return`）
2. 在 Stripe Dashboard / CLI 新增 `checkout.session.completed` 至 webhook 監聽事件
3. 部署後，舊的 `paying` 狀態訂閱（以 PaymentIntent 流程建立）仍可由 `invoice.paid` 正常啟用，無需遷移

## Open Questions

- 無
