# Lore

Implicit knowledge that code, types, tests, and git history can't reveal — business rules, the "why" behind decisions, API maps, and pitfalls.

## How to use

- **Before planning a feature or fixing a bug:** read this index, find the relevant area, read that area's `pitfalls.md` and `business-rules.md` first.
- **When you learn something code can't show:** record it in the right area.
- Each entry carries a one-line meta: `code:` link · `updated:` date · `status:`.

## Areas

<!-- maintained as areas are added -->
| Area | What's in it |
|------|--------------|
| [transcription](transcription/) | 字幕與轉錄 pipeline：videotranscriber.ai、Groq、YouTube captions、相關 queue job 與唯一鎖行為 |
| [subscription](subscription/) | Paddle 與 Stripe 訂閱：webhook 驗簽、plan/price 同步、polymorphic 綁定 |
| [media](media/) | Media 狀態流轉、RSS source 同步、summary 產生 |
| [auth](auth/) | JWT guard、Socialite OAuth、token 與帳號綁定 |

## Optional

- `api-map.md` — feature ↔ API ↔ entry points (API-heavy projects).
- `architecture/` — cross-cutting tech-choice rationale.
