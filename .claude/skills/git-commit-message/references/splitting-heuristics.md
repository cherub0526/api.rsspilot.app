# Commit 切分啟發

一個 commit 只該描述一件邏輯上獨立的變動。當會話完成的變動跨越多個目的時，切分原則如下。

## 必拆情境

- 不同 **type** 的變動（`feat` + `fix`、`refactor` + `fix`）
- 不同**邏輯模組**且可獨立 revert（`auth` 與 `openapi` 同時動到）
- **依賴變動**（`composer.json` / `composer.lock`）vs. **使用依賴的業務碼** — 依賴單獨 commit 讓 `git bisect` 更容易
- 自動產生的檔案（lock file、generated schemas）同時有手動變動 — 手動邏輯獨立
- **breaking change** 一定單獨一個 commit

## 可合併情境

- `feat` + 該功能的新測試 `test`（同目的）
- `fix` + 補該 fix 的回歸測試
- `refactor` 一併更新呼叫端（同一重構的整體）
- 同一 `chore` 的多個設定檔（.gitignore、editorconfig、lint 設定）

## 順序原則

多 commit 依序執行時，排序參考：

1. **依賴層優先**：`chore(deps)` → `feat` → `docs`
2. **底層優先**：model → service → controller → view
3. **獨立可測**：每個 commit push 上去 CI 都該綠燈（至少 build 過）
4. **revert 友善**：後面的 commit 不能暗地依賴前面修飾性的改動

## 範例決策

### 情境 A：一次會話改了 5 個檔

```
composer.json                      # 新裝套件
composer.lock
app/Service/NewService.php          # 新功能邏輯
app/Controller/FooController.php    # 使用 NewService
test/Cases/NewServiceTest.php       # 新 service 測試
CLAUDE.md                           # 文件同步更新
```

**建議切分**：

| # | Type | 內容 |
|---|------|------|
| 1 | `chore(deps)` | composer.json、composer.lock |
| 2 | `feat(<module>)` | NewService.php、FooController.php、NewServiceTest.php |
| 3 | `docs` | CLAUDE.md |

### 情境 B：修 bug 順便整理了鄰近程式碼

```
app/Service/OldService.php          # 修 bug
app/Service/OldService.php          # 同檔順手重構（命名 / 抽函式）
```

**建議切分**：

| # | Type | 內容 |
|---|------|------|
| 1 | `fix(<module>)` | 只含 bug 修復的最小變動 |
| 2 | `refactor(<module>)` | 其他整理 |

若重構非常小且與修復同一段邏輯，可例外合併為 `fix`，但 body 需註明「順帶整理」。

### 情境 C：一次會話跨多個功能

若變動橫跨 3 個以上不相關模組 → 先與 user 確認是否應該**分支切開**，而不是塞進同一次 commit 串列。Skill 的 default follow-through policy 要求「不清楚該切幾個 commit 時先問」。

## 實務檢查

切完後逐一驗證：

- [ ] 每個 commit 的 subject 能被外人看懂（不用看程式碼）
- [ ] 每個 commit 單獨 revert 不會留下語法錯誤或 broken import
- [ ] 若 CI 只跑當前 commit 的 diff，該 commit 能獨立通過基本檢查
- [ ] commit 順序符合相依性（底層先）
