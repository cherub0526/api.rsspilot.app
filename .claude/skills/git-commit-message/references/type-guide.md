# Type 選擇矩陣 & Scope 命名

選錯 type 會讓 changelog 與 semver 推論失準。以下矩陣用於「當變動橫跨多類別時，怎麼切」。

## 白名單

| type | 意義 | semver 影響 | 觸發情境 |
|------|------|-------------|----------|
| `feat` | 新增/修改功能 | minor | 新 endpoint、新 UI、新參數、擴充既有功能能力 |
| `fix` | 修補 bug | patch | 修正錯誤行為、處理 edge case、補漏掉的檢查 |
| `docs` | 只動文件 | – | `*.md`、docblock、CLAUDE.md、README |
| `style` | 純格式，不影響執行 | – | 格式化工具套用、白空格、分號、排版 |
| `refactor` | 重構，行為不變 | – | 抽函式、改目錄結構、改命名、解耦 |
| `perf` | 效能改善 | patch | 減少 query、cache、演算法替換、I/O 優化 |
| `test` | 只動測試 | – | 新增測試檔、修測試、修 fixture |
| `chore` | 建構/輔助工具 | – | 依賴升級、CI/lint 設定、`.gitignore`、tooling |
| `revert` | 撤銷既有 commit | 視情況 | `git revert` 產生的 commit |

## 決策流程

```
檔案變動 → 有改動非測試的程式碼嗎？
├─ 否
│   ├─ 只改 *.md / docblock → docs
│   ├─ 只改 test/、*.test.*、*Test.php → test
│   ├─ 只動 .gitignore / composer.json / CI / lint 設定 → chore
│   └─ 只動格式（空白、分號、排版） → style
└─ 是
    ├─ 行為「有」對外變化
    │   ├─ 新增能力（新 endpoint、新函式、新參數） → feat
    │   ├─ 修正錯誤行為 → fix
    │   └─ 僅優化速度/記憶體使用 → perf
    └─ 行為「無」對外變化 → refactor
```

## 混合變動處理

一個 commit 只能有一個 type。橫跨多類別時：

| 情境 | 處理 |
|------|------|
| feat + 附帶 test | 拆成 `feat` + `test`；若測試是同 commit 產出的驗證，可合併入 `feat` |
| feat + 附帶 docs | 拆成 `feat` + `docs`；若 docs 只是 README 同步更新，可合併入 `feat` |
| refactor 附帶 fix | 拆成 `refactor` + `fix`；bug 修復必須獨立 commit |
| fix + 意外抓到另一個 bug | 各自 `fix`；subject 明確區分 |
| 依賴升級造成 API 調整 | `chore(deps)` + `refactor`（或 `feat`） |

## Scope 命名

- 用**小寫英文短名**；多字用連字號或 camelCase
- 指向「變動核心模組」而非檔案路徑
- 常用 scope 對照：

| 模組 | scope |
|------|-------|
| `app/Controller/` | `controller`、`http`、具體如 `auth`、`course` |
| `app/Model/` + `config/autoload/databases.php` | `db`、`model`、具體如 `user` |
| `app/Exception/` | `exception` |
| `app/OpenApi/` + controller OA 註解 | `openapi` |
| `config/autoload/server.php`、Swoole 設定 | `server` |
| `config/autoload/*.php` 其他 | `config` |
| `composer.json` / `composer.lock` | `deps` |
| `CLAUDE.md` / `README.md` / `docs/` | 省略 scope，用 `docs:` |
| `.claude/skills/*` | `skills` |
| CI、`.github/` | `ci` |

若範圍跨多模組 → 省略 scope，只用 type。

## 反例

- ❌ `feat: 修 bug` — type 與 subject 矛盾，bug 應用 `fix`
- ❌ `update: 升級 PHP` — `update` 不在白名單，應為 `chore(deps)`
- ❌ `feat(app/Controller/IndexController.php): ...` — scope 不該是檔案路徑
- ❌ `fix: 修正錯誤。` — 結尾有句號
- ❌ `FEAT: 新增功能` — type 必須小寫
