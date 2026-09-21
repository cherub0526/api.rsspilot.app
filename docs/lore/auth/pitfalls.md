---
area: auth
kind: pitfalls
---

# auth — Pitfalls

<!--
Each pitfall is a `## heading` + a one-line meta + the body:

## Short title of the gotcha

`code:` `path/to/file.ext` → `symbol` · `updated:` `YYYY-MM-DD` · `status:` `active`

What breaks, why, and what to do instead.
-->

## sanctum 附的 migration 會把 tokenable_id 建成整數

`code:` `database/migrations/*_create_personal_access_tokens_table.php` · `updated:` `2026-09-21` · `status:` `active`

`hypervel/sanctum` 的 migration 用 `$table->morphs('tokenable')`，`tokenable_id` 因此
是 unsigned big integer。但這個專案的 `users.id` 是 **26 字元的 ULID**。

發作方式很難抓：**sqlite 的動態型別讓本機一切正常**，到了 MySQL／MariaDB 則是把
ULID 靜默截斷成 `0`——每一把 token 都指向同一個不存在的使用者，而且沒有任何錯誤
訊息，看起來就只是「認證莫名其妙失敗」或更糟的「認證成了別人」。

所以不能直接 publish 它的 migration，要自己寫一支把 `tokenable_id` 宣告成 `ulid()`，
索引照原本的形狀補回去。日後換套件、升版時要再檢查一次這件事。


## `guard()->refresh()` 發出來的 token 沒有 `exp`，永不過期

`code:` `app/Http/Controllers/API/V1/Auth/RefreshController.php` → `store` · `updated:` `2026-09-16` · `status:` `active`

`JWTManager::refresh()` 的 claims 來自 `buildRefreshClaims()`，它只回傳 `sub` 與**原始**
`iat`；`encode()` 不補預設值，`Lcobucci::getBuilderFromClaims()` 也只照 payload 逐項映射。
於是換發出來的 token 根本沒有 `exp` claim，而 `ExpiredClaim::validate()` 的第一行是
`if (! $exp = ($payload['exp'] ?? null)) return;`——缺 `exp` 直接放行。`required_claims`
又把 `'exp'` 註解掉了，兩道防線同時漏掉同一個 claim，結果是一張**永久有效**的憑證躺在
使用者的 localStorage 裡。

所以這支端點用 `$this->guard()->login($request->user())` 重發，而不是 `guard()->refresh()`。
如果哪天要改回 `refresh()`（例如為了保留原始 `iat` 來實作 `refresh_ttl` 上限），必須同時
確認 `exp` 有被帶上。

## 只斷言回應的 `expires_in`，抓不到 token 本身的問題

`code:` `app/Http/Controllers/Concerns/IssuesAccessToken.php` → `responseAccessToken` · `updated:` `2026-09-16` · `status:` `active`

`expires_in` 是 `config('jwt.ttl') * 60` 算出來的**字面值**，跟回傳的那串 token 裡到底有
沒有 `exp`、值是多少完全無關。上面那個永不過期的 bug 之所以活很久，正是因為
`testRefreshWithToken` 只看回應結構——它從頭到尾都是綠的。

發 token 的端點要測「有效期」，就得拆開 token 的 payload 來看（`RefreshControllerTest::payload()`）。

## 同一秒內換發會拿到逐字相同的 token

`code:` `tests/Feature/API/V1/Auth/RefreshControllerTest.php` → `testRefreshExtendsExpiration` · `updated:` `2026-09-16` · `status:` `active`

claims 只有 `sub` / `iat` / `exp`，三個都以秒為單位，所以在同一秒內登入又換發，簽章
會一模一樣。這不是 bug，但測試不能用「token 字串有沒有變」來判斷換發成功——要嘛推進
`Carbon::setTestNow()`，要嘛改看 `exp` 有沒有往後延。
