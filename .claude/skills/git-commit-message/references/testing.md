# Testing & Validation

此 skill 的驗證分兩層：**mechanical validator**（每次交付都跑）與 **evals**（改版或調 description 時跑）。

## Layer 1：Mechanical validator

用途：在 `git commit` 之前驗證單一訊息是否符合格式規範。

```bash
# 從檔案驗證
python3 scripts/validate_commit_message.py <message-file>

# 從 stdin 驗證
echo -e "fix: 修正登入流程\n\nCo-Authored-By: ..." | python3 scripts/validate_commit_message.py -
```

退出碼：

| code | 意義 |
|------|------|
| 0 | PASS，所有格式規則通過 |
| 1 | FAIL，逐項印出違規原因至 stderr |
| 2 | I/O 或參數錯誤 |

涵蓋規則：
- Header 格式 `<type>(<scope>): <subject>` 或 `revert: type(scope): subject (回覆版本：<hash>)`
- type 白名單
- subject 末端標點
- subject 顯示欄寬 ≤ 50（東亞全形計 2）
- header / body / footer 空行分隔
- body 行寬 ≤ 72
- 末 5 行含 `Co-Authored-By:`
- 疑似敏感資訊（password / secret / token / API key / PEM header）

## Layer 2：Evals

### 檔案位置

`assets/evals/evals.json` 包含兩類測試：

1. **`trigger_evals`** — 驗證 description 是否正確路由
   - `should_trigger`：明確應啟用此 skill 的中／英／混用 query
   - `should_not_trigger`：應由其他 skill 或一般對話處理的 query（branch 命名、PR、rebase、push、策略問題、conflict、log 查詢）

2. **`functional_evals`** — Given / When / Then 格式，驗證輸出契約
   - `f-fix-basic`：單一 bug fix 完整格式
   - `f-feat-no-issue`：無 issue 編號時不捏造
   - `f-mixed-split`：跨 type 變動拆 commit
   - `f-breaking`：含 BREAKING CHANGE footer
   - `f-revert`：revert header 格式
   - `f-sensitive-block`：偵測敏感檔案停下回報
   - `f-no-changes`：working tree clean 時直接告知

### 擴充 evals

新增 trigger case 時：
- 加入使用者實際會講的話，不要只加教科書式 prompt
- 中英文、縮寫、口語皆補；near-miss（容易誤觸發的鄰近 skill query）放 `should_not_trigger`

新增 functional case 時：
- `given`：staging 區狀態 + user 額外提供的脈絡（issue、breaking、等）
- `when`：觸發語句
- `then`：逐項列出可驗證的輸出斷言（type 值、欄位存在、validator 退出碼）

### 跑 trigger eval（optional，當 description 需調整時）

此 skill 未綁定特定 eval harness。若日後要用 `skill-creator-advanced` 的工具：

```bash
python3 ~/.claude/skills/skill-creator-advanced/scripts/run_eval.py \
  --eval-set .claude/skills/git-commit-message/assets/evals/evals.json \
  --skill-path .claude/skills/git-commit-message \
  --model <model-id>
```

輸出包含 `hit@1`、`hit@3`、false positive 與 neighbor confusion matrix。

### Regression 注意

- 修改 description / SKILL.md 時，先跑既有 trigger evals，確認中文觸發與 near-miss 沒漂
- 修改 `scripts/validate_commit_message.py` 時，用 functional evals 的範例訊息做回歸
- 新增 type 或格式規則 → 同步更新 `references/quality_checklist.md`、`references/type-guide.md`、`evals.json`
