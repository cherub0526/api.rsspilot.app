# Body 結構範本

body 可省略（瑣碎變動、subject 已足夠自解釋時），但一旦寫就應結構化。以下範本照 type 分類。

## 通用原則

- 每行 ≤ 72 欄寬（中文全形佔 2 欄 → 每行實際 ≤ 36 全形字）
- 段落間空一行；同段落的條列可連排
- 先描述「為什麼」再描述「做了什麼」；避免單純複述 diff
- 可引用 issue、PR、錯誤訊息、關聯 commit 短 hash

## 範本 1：Bug fix（`fix`）

```
<type>(<scope>): <subject>

問題：
1. 第一個症狀，用使用者可感知的語言描述
2. 第二個症狀

原因：
1. 根本原因（程式碼層面）
2. 若有多個原因，逐條列出

調整項目：
1. 具體變動的檔案 / 函式 / 邏輯
2. ...

issue #xxxx

Co-Authored-By: ...
```

**使用時機**：修 bug、修邏輯錯誤。若只是一行改動可省略 body。

## 範本 2：新功能（`feat`）

```
<type>(<scope>): <subject>

因應新需求做調整：
<一段話說明需求背景或業務動機>

調整項目：
1. <檔案 / 模組>：<做了什麼>
2. ...

issue #xxxx

Co-Authored-By: ...
```

**使用時機**：新增 endpoint、新增功能、擴充既有能力。

## 範本 3：重構（`refactor`）

```
<type>(<scope>): <subject>

重構原因：
<為什麼要重構，例：耦合度過高、測試困難、命名不符現狀>

變動範圍：
1. <模組 A>：<怎麼動>
2. <模組 B>：<怎麼動>

風險 / 注意事項：
- 行為無變化；已跑完既有測試
- 若有潛在影響，條列出來

Co-Authored-By: ...
```

**使用時機**：抽函式、搬目錄、解耦、改命名。**行為必須不變**。

## 範本 4：效能改善（`perf`）

```
<type>(<scope>): <subject>

效能瓶頸：
<原本的問題，例：N+1 query、不必要的 I/O>

改善方式：
<具體做法，例：加 eager loading、改用 cache>

效果：
- 執行時間：Xs → Ys
- Query 數：N → M
- Memory：... → ...

Co-Authored-By: ...
```

**使用時機**：針對效能明確優化。若無量測數據至少描述改善方向。

## 範本 5：Breaking change

```
<type>(<scope>): <subject>

<一般 body 內容>

BREAKING CHANGE: <簡述不兼容變動>

影響：
- <誰會受影響>

原因：
- <為什麼必須 break>

遷移方式：
- <使用者該如何升級、設定或調用>

issue #xxxx

Co-Authored-By: ...
```

**使用時機**：API 移除、行為語意改變、設定格式變更。**必須**明寫遷移方式。

## 範本 6：Revert

```
revert: <原 type>(<原 scope>): <原 subject> (回覆版本：<short-hash>)

<一段話說明為什麼要 revert，以及後續計畫>

Co-Authored-By: ...
```

**使用時機**：用 `git revert` 撤銷既有 commit。保留原 type/scope/subject 以便追溯。

## 範本 7：依賴升級（`chore(deps)`）

```
chore(deps): <升級 xxx 從 a.b.c 到 x.y.z>

升級原因：
- <例：修補 CVE-xxxx、相容新版 PHP、取得新功能>

相容性檢查：
- <已驗證的項目：測試、手動測、lint>
- <已知的行為差異，若有>

Co-Authored-By: ...
```

**使用時機**：`composer update`、`npm update`、套件版本調整。
