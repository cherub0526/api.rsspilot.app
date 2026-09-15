---
area: auth
kind: business-rules
---

# auth — Business rules

<!--
Each rule is a `## heading` + a one-line meta + the body. Capture the "why" code can't show.

## Name of the rule

`code:` `path/to/file.ext` → `symbol` · `updated:` `YYYY-MM-DD` · `status:` `active`

The rule, the reasoning, and edge cases.
-->

## Session 沒有絕對上限，只要持續使用就不會過期

`code:` `app/Http/Controllers/API/V1/Auth/RefreshController.php` → `store` · `updated:` `2026-09-16` · `status:` `active`

存取憑證的 TTL 是 `JWT_TTL`（正式站 300 分鐘），前端會在剩餘時間低於 TTL 的 20%
時自動呼叫 `POST /v1/auth/refresh` 換一張新的。換發沒有次數或總時長上限，所以
**一個天天開 app 的使用者永遠不會被登出**，只有連續閒置超過一個 TTL 才需要重新登入。

這是刻意的，不是疏漏。`config/jwt.php` 的 `JWT_REFRESH_TTL=20160`（14 天）看起來像
上限，但 `hypervel/jwt` **沒有任何 validation 在檢查它**——`jwt.validations` 只有
`RequiredClaims` 與 `ExpiredClaim`，前者的 `required_claims` 也沒有把 `exp` 列進去。
我們選擇不自己補這個檢查：換發只在 token 還活著時可行（`/v1/auth/refresh` 掛 `auth`
middleware），憑證外流的曝險窗口仍然是一個 TTL，而硬加 14 天上限換來的是「每兩週被踢出
一次」的體驗倒退。

要改成「有絕對上限」的話，動的地方是 `RefreshController`：它現在用 `login()` 重發，
`iat` 會跟著更新；要有上限就得改回保留原始 `iat` 並自行比對 `refresh_ttl`。

## 登出是純前端行為

`code:` `app/Http/Controllers/API/V1/Auth/LogoutController.php` → `store` · `updated:` `2026-09-16` · `status:` `active`

`POST /v1/auth/logout` 只回 `OK.`，不撤銷任何東西——`jwt.blacklist_enabled` 是 `false`，
撤銷機制根本沒開。實際的登出是前端刪掉 localStorage 裡的 token，而**舊 token 到 `exp`
為止仍然可用**。換發後的舊 token 同理。

接受這件事的前提是 TTL 夠短。要真的能撤銷，得開 `blacklist_enabled`（需要可用的
cache storage），並注意 `blacklist_grace_period=0` 會讓「換發當下正在飛的並行請求」
拿著剛被加進黑名單的舊 token 撞牆。
