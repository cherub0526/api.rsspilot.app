# Quality Checklist

此檔承擔兩個用途：
1. **Commit delivery gate**（下半頁）：每次產生 commit 訊息或交付多 commit 前的逐項確認
2. **Skill readiness audit**（文末）：改版此 skill 時回填的 audit 結果

---

## Commit delivery gate

每次產生 commit 訊息前或交付多 commit 前，逐項確認。任何一項 FAIL → 回到對應 workflow step 修正。

## 格式確認（mechanical）

- [ ] Header 第一行符合 `<type>(<scope>): <subject>` 或 `<type>: <subject>`
- [ ] `type` 在白名單：`feat` / `fix` / `docs` / `style` / `refactor` / `perf` / `test` / `chore` / `revert`
- [ ] `subject` ≤ 50 顯示欄寬（中文全形佔 2 欄）
- [ ] `subject` 結尾**無句號**（`.`、`。`、`!`、`?`）
- [ ] Header 與 body 之間**空一行**
- [ ] Body 每行 ≤ 72 顯示欄寬
- [ ] Body 與 footer 之間**空一行**
- [ ] 末段含 `Co-Authored-By: <當前模型> <noreply@anthropic.com>`（模型名依當下實際版本填寫）
- [ ] `scripts/validate_commit_message.py` 退出碼 0

## 要求/規範確認（semantic）

- [ ] `type` 選擇符合 `references/type-guide.md` 的決策矩陣
- [ ] `subject` 以動詞開頭（「新增」「修正」「重構」「移除」「升級」…）
- [ ] 若變動重要 → body 用「問題 / 原因 / 調整項目」或類似結構化段落
- [ ] issue 編號正確填入 footer（格式：`issue #xxxx`）
- [ ] breaking change 另起段落 `BREAKING CHANGE: ...`，含影響、原因、遷移方式
- [ ] revert 格式：`revert: type(scope): subject (回覆版本：<short-hash>)`
- [ ] 多 commit：每則獨立可 review；commit 順序符合相依性（先底層、後上層）

## 常見錯誤確認

- [ ] 沒用 `git add -A` / `git add .` 一口氣加全部
- [ ] 沒用 `--no-verify` 繞過 hook（除非 user 明確要求）
- [ ] 沒用 `--amend` 修改既有 commit（除非 user 明確要求）
- [ ] 沒把測試 + 功能 + 文件混在同一個 commit（應按 type 拆）
- [ ] subject 不是英文直譯（此專案偏繁中）
- [ ] 沒把 debug 訊息、`console.log`、`var_dump` 留在變動中
- [ ] `.env` / `credentials.*` / `*.key` / `*.pem` 不在 staging 區

## Safety gate（敏感檔案）

提交前用 `git diff --staged --name-only` 檢查，若出現以下路徑 → 停下回報 user：

- `.env`、`.env.*`（例外：`.env.example`）
- `**/credentials*`、`**/secrets*`
- `*.key`、`*.pem`、`*.p12`、`*.pfx`
- `id_rsa`、`id_ed25519`、`*.asc`

## 最終 gate

| 項目 | 狀態 |
|------|------|
| 格式確認全通過 | ☐ PASS / ☐ FAIL |
| 要求/規範確認全通過 | ☐ PASS / ☐ FAIL |
| 常見錯誤確認全通過 | ☐ PASS / ☐ FAIL |
| Safety gate 通過 | ☐ PASS / ☐ FAIL |

**四項全 PASS 才可執行 `git commit`。** 任一 FAIL 停下修正後重檢。

---

## Skill readiness audit

改版此 skill 時跑 skill-creator-advanced 提供的 audit script，把結果回填於此。

### 最近一次 audit（version 1.2.0）

| 檢查項目 | 指令 | 結果 |
|----------|------|------|
| 格式結構 | `format_check.py` | ✅ 0 error；1 warning（`\bUse when\b` trigger regex 為上游已知 false-positive，description 實際含 `Use when user`）|
| 最小合規 | `quick_validate.py` | ✅ valid |
| 本地引用 | `audit_skill_references.py` | ✅ 0 issues / 7 source files |
| 未引用檔 | `audit_unreferenced_files.py` | ✅ 0 issues / 8 referenced files |
| 命名 surface | `check_skill_name_surface.py` | ✅ 0 blocking |
| Overlap | `audit_skill_overlap.py` | ✅ hit@1=0.158（與 openapi-php-docs；可接受）|
| OpenClaw frontmatter | `audit_openclaw_frontmatter.py` | ⛔ `homepage is required` — 本 skill 不公開發布，刻意不填；忽略 |

### PASS/FAIL gate

| 項目 | 狀態 |
|------|------|
| quick_validate 通過 | ✅ PASS |
| 引用 / 未引用檔案皆解釋得通 | ✅ PASS |
| Overlap 無危險相衝 | ✅ PASS |
| Trigger language 可匹配上游 heuristic（bug 除外） | ⚠️ 已解釋 |
| 非公開資訊（homepage / license） | ⚠️ 刻意跳過 |

**Skill readiness：PASS**（已跑 audit、回填結果、例外已解釋）
