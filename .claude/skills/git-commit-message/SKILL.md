---
name: git-commit-message
description: Use when user 說「寫 commit」「幫我 commit」「分別 commit」「revert commit」「產生 commit 訊息」，產出符合 Header/Body/Footer 格式的 git commit 指令（type 白名單 feat/fix/docs/style/refactor/perf/test/chore/revert、subject ≤ 50 欄寬、body ≤ 72 欄寬、末段 Co-Authored-By）。不處理分支命名、PR 標題、rebase/squash 策略、merge conflict 解決。
version: 1.2.0
metadata: {"author": "Ethan"}
---

# Git Commit Message

規範此專案所有 git commit 訊息。採 Conventional Commits 風格，分為 **Header / Body / Footer**。Subject ≤ 50 顯示欄寬、body 每行 ≤ 72 欄寬、type 僅允許白名單列出者。細節放 `references/`，可執行檢查放 `scripts/`。

不處理：分支命名、PR 標題/內文、rebase/squash 策略、merge conflict 決策。

## Format（速查）

```
<type>(<scope>): <subject>

<body>

<footer>
```

- **type**（必填）：`feat` `fix` `docs` `style` `refactor` `perf` `test` `chore` `revert`
- **scope**（可選）：影響範圍，如 `auth`、`openapi`、`db`；詳見 `references/type-guide.md`
- **subject**（必填）：≤ 50 欄寬；動詞開頭；**結尾不加句號**
- **body**（可選但建議）：每行 ≤ 72 欄寬；推薦結構「問題 / 原因 / 調整項目」
- **footer**（可選）：`issue #xxxx`、`BREAKING CHANGE: ...`
- **末段固定保留** `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

> 中文全形字佔 2 欄寬；validator 依東亞寬度計算，不是 byte 數。

<role>
Act as a release engineer who enforces this project's commit message style guide, knows the allowed type whitelist, and writes concise Traditional Chinese subjects with structured bodies.
</role>

<decision_boundary>
Use when:
- User 說「寫 commit」「幫我 commit」「commit message」「產生 commit 訊息」「分別 commit」「切 commit」
- 會話結束要把已完成變動送進 git
- 要求 revert 既有 commit 並遵循專案格式

Do not use when:
- 討論 branch 命名、PR 標題/內文、marketplace release notes
- rewriting git history（`git rebase -i`、`git squash`、`git reset`）— 屬 git 操作
- User 明說只要 `git add`，不要 commit
- 詢問「要不要 squash？」「該不該切？」等策略性問題 — 簡短回答後交還判斷

Inputs:
- `git status` / `git diff --staged` / `git diff` 結果
- 變動動機、issue 編號、是否為 breaking change

Successful output:
- 一個或多個 `git commit -m "$(cat <<'EOF' ... EOF)"` 指令
- 通過 `.claude/skills/git-commit-message/scripts/validate_commit_message.py` 檢查
- Footer 末段含 Co-Authored-By line
</decision_boundary>

## Primary use cases

1) **寫單一 commit**
   - Trigger：「幫這次改動寫 commit」「請為這次的改動寫 git commit」
   - Inputs：`git status` + `git diff`、變動意圖
   - Output：一則完整 commit 指令

2) **切多個 commit**
   - Trigger：「把這些改動分成多個 commit」「請分別 commit」
   - Inputs：diff、各變動的邏輯邊界；參 `references/splitting-heuristics.md`
   - Output：依主題分組的多則 commit 指令，依序執行

3) **revert commit**
   - Trigger：「revert 剛剛的 commit」「幫我 revert xxxxxx」
   - Inputs：要 revert 的 hash 與原 subject
   - Output：`revert: type(scope): subject (回覆版本：xxxx)` 格式

## Routing boundaries

- **鄰近 skill**：`deploy`（部署前檢查）、`review`（branch diff 審查）、`openspec-*`（變更提案文件）
- **Negative triggers**：「commit 該怎麼切？」「要不要 squash？」「PR 標題怎麼寫？」「分支怎麼命名？」
- **Handoff**：策略性問題簡短回答後交還 user

<workflow>
Step 0: Orient
- Action：執行 `git status` 與 `git diff --staged`、`git diff`（未 staged 部分），列出變動檔案與摘要
- Validation：若無任何變動 → 告知 user 無需 commit，結束

Step 1: Classify type & scope
- Action：對每組邏輯變動選 type，規則見 `references/type-guide.md`
- Action：若變動橫跨多 type → 拆多個 commit；先確認 user 同意切分邊界
- Validation：type 在白名單內；scope 用小寫、英文短名

Step 2: Draft subject
- Action：動詞開頭，簡述「做了什麼」；繁體中文為主，技術名詞可混用英文
- Validation：≤ 50 欄寬、結尾無句號

Step 3: Draft body
- Action：重要變動照「問題 / 原因 / 調整項目」模板（見 `references/body-templates.md`）；瑣碎變動可省略
- Validation：每行 ≤ 72 欄寬；段落間空一行

Step 4: Footer
- Action：user 提供 issue 編號 → 加 `issue #xxxx`；breaking change → 獨立段落 `BREAKING CHANGE: ...`
- Validation：footer 與 body 之間空一行；Co-Authored-By 放最末行

Step 5: Validate
- Action：把訊息存為暫存 → `python3 .claude/skills/git-commit-message/scripts/validate_commit_message.py <file>`
- Validation：退出碼 0 才提交；有錯誤回 Step 2-4 修正

Step 6: Commit
- Action：用 HEREDOC（`cat <<'EOF'`）傳入 `git commit -m`
- Action：已授權 → 直接執行；未授權 → 僅輸出指令
- Validation：`git log -1` 確認訊息格式正確
</workflow>

<output_contract>
輸出順序：
1. 可直接複製貼上的 `git commit` 指令（HEREDOC、含 Co-Authored-By）
2. 多 commit 時依執行順序列出，並給出對應的 `git add <path>` 清單
3. 非顯而易見時，一句話說明 type/scope 選擇理由

不輸出：
- 冗長的格式說明（已在本檔 + references）
- 同一個 commit 的多版本替代方案（除非 user 索取）
- commit 訊息以外的 commentary
</output_contract>

<tool_rules>
- Bash：先跑 `git status` + `git diff` 再動筆
- HEREDOC：`cat <<'EOF' ... EOF` 包訊息，避免 `$` / 反引號跳脫
- Git：**不**用 `--no-verify`、`--amend`（除非 user 明確要求）
- Staging：明列 `git add <path>`，**不**用 `git add -A` / `git add .`
- Script：完成草稿後必跑 `.claude/skills/git-commit-message/scripts/validate_commit_message.py`
</tool_rules>

<default_follow_through_policy>
- **直接做**：讀 diff、起草訊息、跑 validator、`git add <path>` + `git commit`（user 已授權時）
- **先問再做**：變動跨多 type / 要切幾個 commit 不明確 / issue 編號未知
- **停下回報**：偵測到 `.env`、`credentials.*`、`*.key`、`*.pem`、token 類檔案 → **不**提交，先告知 user
</default_follow_through_policy>

<examples>
### Canonical 單一 fix（含 body + issue + footer）

```bash
git commit -m "$(cat <<'EOF'
fix(course): 修正課程列表分頁 N+1 query

問題：
1. 列表頁載入 > 500ms，每筆課程都觸發獨立的 lecturer / tag 查詢

原因：
1. CourseController::index 未對關聯使用 eager loading
2. Course model 缺 lecturer/tags 關聯定義

調整項目：
1. Course::with(['lecturer', 'tags']) 加入 eager loading
2. Course model 補上 lecturer/tags 關聯

issue #2108

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### Canonical 多 commit 切分（chore → feat → docs）

```bash
git add composer.json composer.lock
git commit -m "$(cat <<'EOF'
chore(deps): 安裝 zircote/swagger-php

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"

git add app/OpenApi/ app/Controller/IndexController.php
git commit -m "$(cat <<'EOF'
feat(openapi): 建立 OpenAPI 元件目錄與首頁端點文件

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"

git add CLAUDE.md
git commit -m "$(cat <<'EOF'
docs: 補充 OpenAPI 使用說明

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

更多型別（revert / breaking / perf / test）見 `references/examples-library.md`。
</examples>

## References

- `references/quality_checklist.md`：readiness gate（每次交付前檢核）
- `references/type-guide.md`：type 選擇矩陣 + scope 命名
- `references/body-templates.md`：body 結構範本
- `references/splitting-heuristics.md`：切分多 commit 的判斷
- `references/examples-library.md`：完整範例（fix / feat / breaking / revert / chore…）
- `references/testing.md`：evals 與 validator 使用方式

## Scripts

- `.claude/skills/git-commit-message/scripts/validate_commit_message.py <file>`：驗證 subject 長度、type、行寬、footer；退出碼 0 代表通過

## Assets

- `assets/evals/evals.json`：trigger evals（should / should-not）與 functional evals（given / when / then）
