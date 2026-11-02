---
area: subscription
kind: pitfalls
---

# subscription — Pitfalls

<!--
Each pitfall is a `## heading` + a one-line meta + the body:

## Short title of the gotcha

`code:` `path/to/file.ext` → `symbol` · `updated:` `YYYY-MM-DD` · `status:` `active`

What breaks, why, and what to do instead.
-->

## 判斷「額度用盡」不要用 ChatQuotaSnapshot::exceeded()

`code:` `app/Services/ChatQuotaSnapshot.php` → `exceeded()` · `updated:` `2026-08-19` · `status:` `active`

`exceeded()` 是 `used > limit`（嚴格大於），它回答的是「**已經超量**」——為降級後 `used` 已經超過新方案 `limit` 的情境而寫的，用來收斂顯示用的數字。

它不是「還能不能再問一次」。額度剛好用完（`used === limit`）時 `exceeded()` 回 `false`，拿它當閘門會在最關鍵的那一刻放行。

要擋下一次呼叫，用跟 `ChatQuotaService::consume()` 同一個口徑（`count >= limit`）：

```php
!$snapshot->isUnlimited() && $snapshot->remaining() === 0
```

`isUnlimited()` 一定要先判——`remaining()` 對不限制的方案也回 `0`，漏了前置條件會讓 `chat_limit = 0`（不限制）變成「一次都不能用」，方向剛好相反。

## prices.price 一律是 USD，但欄位沒有記錄幣別

`code:` `database/migrations/*_create_prices_table.php`、`database/seeders/PlanPriceSeeder.php` · `updated:` `2026-08-25` · `status:` `active`

`prices.price` 是 `decimal(8,2)`，**沒有 currency 欄位**。全專案的約定是這個數字一律為 **USD**，但這件事只存在於約定，schema 上完全看不出來——寫入或讀取時很容易照著在地幣別的直覺去填。

2026-08-25 盤點時 seeder 裡就有這個症狀：Pro 是 `monthly 299` + `annually 48`，Advance 是 `monthly 499` + `annually 96`。照 USD 讀就是月繳 $299、年繳 $48/年，年繳比月繳便宜 98%，兩個數字不可能同時成立。`48` 與 `96` 剛好是 `4×12` 與 `8×12`，看起來是認真訂的美金年費；`299` / `499` 則像是新台幣的數字沒換算就寫進去了。

碰到這張表時：

- 新增或修改價格前先確認拿到的數字是 USD，不要沿用畫面上或討論裡的在地幣別數字。
- 月費 × 12 與年費要對得起來（正常年繳折扣是 17%，也就是送兩個月）。差距大到不合理就是幣別搞混了，不是折扣策略。
- 實際向使用者收的多幣別價格是金流商（目前為 Stripe）那邊在管的，這張表存的是基準值。改這裡不等於改了 Stripe 上的價格。
- 這個數字是**未稅**基準。Stripe 不是 Merchant of Record，稅金要在結帳時外加，別把含稅價寫進這張表（見 `business-rules.md` 的稅務責任轉移）。

## Stripe 的 Product 與 Price 建出來就刪不掉，清理只能靠封存

`code:` `app/Console/Commands/Stripe/Sync.php` → `repointPrice`、`app/Observers/StripePriceObserver.php` → `updated` · `updated:` `2026-08-30` · `status:` `active`

Stripe API 對這兩種物件的刪除支援，比直覺想的少很多：

| 動作 | 結果 |
|---|---|
| `DELETE /v1/products/:id` | 只有該 product **從來沒掛過 price** 才成功，否則回 `This product cannot be deleted because it has one or more user-created prices.` |
| `DELETE /v1/prices/:id` | **這個 endpoint 不存在**，回 `Unrecognized request URL` |

關鍵在於 product 的判定看的是「price 存不存在」，**不是「price 啟不啟用」**——把 price 跟 product 都設成 `active=false` 之後再刪，一樣被擋。而 price 又永遠拿不掉，所以結論是：**Stripe 上的 product / price 一旦建立就是永久的**。

實務上要記住三件事：

- 任何「清掉舊方案 / 清掉測試殘留」的需求，能做的只有 `active=false` 封存。不要規劃刪除流程，也不要假設測試環境可以清乾淨。Dashboard 在測試模式下的「Delete product」確實刪得掉（會連 price 一起清），但 API 沒有對應能力——**Dashboard 做得到不等於可以自動化**。
- 因此**改價一律是三步：建新 price → 改寫 `stripes` 映射 → 封存舊 price**，沒有「更新 price 金額」這種操作。少了封存那步，舊價格會繼續出現在結帳頁；少了改映射那步，`stripes` 會一直指著舊金額。2026-08 盤點時 Pro / Advance 四筆映射全部對不上（DB 12.99/129/24.99/249，Stripe 還是 5/50/10/100），成因就是 `prices.price` 被改過但這三步一步都沒做。
- Model 的 observer 每次 `created` 都會在 Stripe 建新 product。測試與 seeder 反覆跑的結果是測試環境累積了 200+ 個同名的重複 product，而且**清不掉、只能封存**。要驗證同步邏輯時優先用 `stripe:sync --dry-run`，不要靠反覆重建資料試。

與上一條的分工：上一條講「改 `prices.price` 不等於改了 Stripe 上的價格」，這條講「改 Stripe 上的價格只能用新增取代修改」。

## Paddle 建 price 不指定 tax_mode，會落在 location 而不是 external

`code:` `app/Console/Commands/Paddle/Sync.php` → `handle` · `updated:` `2026-10-31` · `status:` `obsolete`

> 2026-10 起不再適用：Paddle 整合與 `paddle:sync` 已移除。留著是因為「稅外加要明寫、不要吃預設」這個判斷延續到了 Creem（`creem:sync` 的 `TAX_MODE = exclusive`）。

`CreatePrice` 的 `taxMode` 是選填，不帶的時候 Paddle **不是**給你 `account_setting`，實測回來的是 `location`——依買家所在地的顯示慣例決定含不含稅。2026-09-11 第一次跑 `paddle:sync` 建出來的六筆 price，`tax_mode` 全是 `location`。

這件事對我們是錯的方向：`prices.price` 是**未稅**基準價（見上面〈prices.price 一律是 USD〉），而 `location` 會讓歐盟／英國那種含稅顯示的市場把 12.99 當成**含稅價**——VAT 不是外加給買家，是從我們的收入裡扣。Paddle 是 MoR 會代收代繳，所以帳面上不會噴錯，只會少收。

要的是 `TaxMode::External()`（價格未稅、稅在結帳時外加），建立時就要明寫。

修既有的 price 時有個跟 Stripe 相反的地方值得記住：**Paddle 的 price 可以更新**，`PATCH /prices/:id` 帶 `tax_mode` 就會生效，不必像 Stripe 那樣「建新的 + 改映射 + 封存舊的」。所以同步指令對已存在的 price 不能只是 `continue` 跳過，否則早建好的那批永遠修不到。

代價是：`external` 的結帳頁對歐盟／英國 B2C 顯示的是未稅價，跟當地「標價即含稅」的消費者慣例不同。要改成含稅顯示是 `internal` / `location` + 重新定價的商業決策，不是把這個參數轉回去就好。

## Clink 的金額是主幣別，跟 Creem / Stripe 的美分相反

`code:` `app/Services/ClinkSubscriptionService.php` → `createCheckout()`、`app/Console/Commands/Clink/Sync.php` → `expectedPrice()` · `updated:` `2026-10-31` · `status:` `active`

Clink 的 `unitAmount` 與 checkout 的 `originalAmount` 都是**主幣別**：US$12.99 送 `12.99`。
沿用 Creem / Stripe 的 `stripeUnitAmount()`（美分）送 `1299`，Clink 會照收 US$1,299，
不會報錯。程式一律送 `(float) $price->price`，測試斷言 request body 裡是 `12.99`。

## Clink API 沒有稅務模式欄位，稅外加只能向 Clink 書面確認

`code:` `app/Console/Commands/Clink/Sync.php` → `taxProblem()`、`app/Console/Commands/Clink/Verify.php` · `updated:` `2026-10-31` · `status:` `active`

Creem 有 `tax_mode: exclusive` 可以明寫，Clink 沒有：OpenAPI 的 product / price 上找不到
`taxBehavior`、`taxMode` 之類的欄位，文件也沒寫價格是稅內含還是稅外加（只有
`taxCategory`，我們送 `software_service`）。稅內含的話，同樣標價 $12.99，歐盟客人的 VAT 是從
我們的收入裡扣，實收約 $10.82，而且不會有任何地方報錯。

使用者定案稅外加，所以 `clink:sync` 與 `clink:verify` 在看不到 Clink 回報 exclusive 時
**一律報失敗（exit code 1）**，不默默接受。這不是 bug：它會一直紅到 Clink 書面確認帳號是
稅外加、或 API 補上欄位為止。`taxProblem()` 已經認得幾個可能的欄位名，Clink 補上之後檢查會
照實通過或失敗。

## Clink 的試用期綁在 price 上，結帳時不能覆寫

`code:` `app/Services/ClinkSubscriptionService.php` → `priceIdFor()` · `updated:` `2026-10-31` · `status:` `active`

`POST /checkout/session` 沒有任何試用欄位，試用只能來自 price 的
`recurringDetails.trialPeriodDays`。「14 天試用只給第一次」因此跟 Creem 一樣，靠每個付費 Price
在 Clink 建兩個 price（`clinks.variant` = standard / trial），結帳時依資格挑。找不到對應的
變體要讓結帳失敗，不能退回另一個：退錯方向不是當場收了有資格者的錢，就是把試用送給已經用過的人。

試用 variant 的 checkout 仍送原價 `originalAmount`，由 Clink 依 `trialPeriodDays` 決定當下收 0。
這是推論，沙盒還沒驗證；若 Clink 要求送 0，要改成依 variant 決定。

## Clink webhook 不保證順序，訂閱物件沒有 updated_at，所以回查 API

`code:` `app/Services/ClinkSubscriptionService.php` → `handleEvent()`、`app/Services/ClinkWebhookSignature.php` · `updated:` `2026-10-31` · `status:` `active`

Creem 那條路直接採信 payload，靠 `updated_at` 擋亂序。Clink 的訂閱物件沒有 `updated_at`，
只有事件外殼的 `created`（毫秒），事件也會重複送達。所以 Clink 的事件**只當通知**：對到我方
訂閱後打 `GET /subscription/{id}`，用當下的狀態覆寫。亂序、重送幾次結果都一樣，不需要事件去重表；
API 掛掉時回 5xx，Clink 會依退避重送（約 17 小時內 10 次，之後進 DLQ，只能在 dashboard 逐筆重送）。

其他容易踩的點：

- 簽章是 `HMAC-SHA256("{X-Clink-Timestamp}.{rawBody}")`，時間戳是**毫秒**，要擋 5 分鐘外的請求。
  官方 TypeScript SDK 的 `verifyAndGet()` 只比 HMAC 而且用 `===`，不要照抄。
- 在 dashboard 註冊 endpoint 時事件要逐一勾選，`subscription.*` 萬用字元不被接受。
  `clink:verify` 會比對 `ClinkController::HANDLED_EVENTS`。
- `subscription.created` 不是開通訊號；一般續約只送 `subscription.updated.renewed`，不重送
  `activated`。只聽其中一個會漏狀態。
- 時間欄位一律當毫秒（文件只寫「Unix timestamp」，範例全是 13 位數）。`currentPeriodStart`
  建立後就不變，本期終點看 `recurringInvoiceItem.periodEnd`，試用中看 `trialEnd`。

## Clink API 的信封與時間戳

`code:` `app/Services/ClinkClient.php` · `updated:` `2026-10-31` · `status:` `active`

- 回應是 `{code, msg, data}`。**HTTP 200 但 `code` 不是 200 也是失敗**，只看 HTTP 狀態會把錯誤當成功。
- 列表端點（`GET /webhook/endpoints`、`GET /price`、`GET /product`）沒有 `data`，資料在 `rows`。
- `X-Timestamp`（毫秒）每次請求現算。sandbox 容許 ±24 小時、production 只容許 2 分鐘，
  快取的時間戳在 sandbox 全過、到 production 全掛。
- 成功導回時 Clink 在 success URL 後面附加 `sessionId`（camelCase），Stripe 是 `session_id`。
  前端靠這個差異分派回站流程，所以 `CLINK_SUCCESS_URL` 本身不能帶 `session_id` / `checkout_id`。
- `merchantReferenceId` 不是冪等鍵，同值送兩次得到兩個都能付的 session。

