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
