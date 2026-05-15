# Reset Password Email — Design Spec

**Date:** 2026-05-16
**Status:** Approved

## Overview

Replace the existing forgot-password email template with the polished, table-based HTML design from open-design (`email-reset-password.html`), adapted for the Audistilizer brand and this project's data flow.

## Scope

Four files change. No changes to routing, controller, or Mailable class interface.

## Files Changed

### 1. `resources/views/emails/reset-password.blade.php`

Full replacement. The new template is a table-based HTML email compatible with Outlook (VML fallbacks included). Adaptations from the design source:

- Hardcoded Chinese text → `__()` i18n calls (see lang section below)
- `href="#"` on CTA button → `{{ $url }}` (passed from Mailable)
- Hardcoded expiry date → `{{ __('mails.reset_password.line_2', ['count' => $minutes]) }}`
- Logo `src="logo.png"` → `{{ asset('/logo2.png') }}`
- Footer links (隱私權政策, 服務條款, 取消接收此類郵件) → **removed**; footer retains only copyright line

Translation keys used in the template:
| Key | Usage |
|-----|-------|
| `mails.reset_password.subject` | `<title>` tag |
| `mails.reset_password.greeting` | 您好 |
| `mails.reset_password.line_1` | Body paragraph (reset request) |
| `mails.reset_password.action` | CTA button label |
| `mails.reset_password.line_2` | Expiry notice (`:count` = minutes) |
| `mails.reset_password.fallback_text` | Fallback URL box label |
| `mails.reset_password.security_note` | Security disclaimer paragraph (new key) |
| `mails.reset_password.footer_text` | Copyright line (`:year` param) |

### 2. `lang/zh_TW/mails.php`

Update existing keys + add `security_note`. All keys are **plain text** (no HTML tags); the Blade template applies any inline styling. `footer_text` retains the `&copy;` entity following the existing project pattern.

```php
'subject'       => '重設您的 Audistilizer 密碼',
'greeting'      => '您好，',
'line_1'        => '我們收到了重設 Audistilizer 帳號密碼的請求。若您確認此操作，請點擊下方按鈕來設定新密碼。若您的連結已失效，請重新提出申請。',
'action'        => '重設密碼',
'line_2'        => '此連結將於 :count 分鐘後失效',
'fallback_text' => '如果按鈕無法點擊，請複製以下連結貼到瀏覽器：',
'security_note' => '如果您沒有要求重設密碼，請忽略此郵件，您的帳號依然安全，密碼不會被更改。Audistilizer 絕不會透過電子郵件要求您提供密碼。',
'alt_logo'      => 'Audistilizer 標誌',
'footer_text'   => '&copy; :year Audistilizer Inc.',
```

Keys removed: `salutation`, `team_name` (no longer used in new template).

### 3. `lang/zh_CN/mails.php`

Same structure as zh_TW, converted to Simplified Chinese.

### 4. `lang/en/mails.php`

Same structure, English equivalents:

```php
'subject'       => 'Reset Your Audistilizer Password',
'greeting'      => 'Hello,',
'line_1'        => 'We received a request to reset the password for your Audistilizer account. Click the button below to set a new password. If your link has expired, please submit a new request.',
'action'        => 'Reset Password',
'line_2'        => 'This link expires in :count minutes',
'fallback_text' => 'If the button does not work, copy and paste the URL below into your browser:',
'security_note' => 'If you did not request a password reset, you can ignore this email — your account is safe and your password will not be changed. Audistilizer will never ask for your password by email.',
'alt_logo'      => 'Audistilizer logo',
'footer_text'   => '&copy; :year Audistilizer Inc.',
```

## Data Flow (unchanged)

```
ForgotPasswordController::store()
  → URL::temporarySignedRoute() → $resetUrl (string)
  → Mail::to($user->email)->send(new ResetPasswordMail($resetUrl, $minutes=60))

ResetPasswordMail::build()
  → subject(__('mails.reset_password.subject'))
  → view('emails.reset-password', ['url' => $resetUrl, 'minutes' => $minutes])
```

## Out of Scope

- Plain-text fallback email version
- Controller or Mailable signature changes
- Footer link URLs (left as future work)
- `salutation` / `team_name` translation keys (removed from template, can be deleted from lang files)
