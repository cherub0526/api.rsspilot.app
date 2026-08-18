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
