# Reset Password Email Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the existing reset-password Blade email template with the polished table-based HTML design from open-design, using Audistilizer branding across all three locales.

**Architecture:** Four files change — the Blade template (full replacement) and three lang files (key updates + new `security_note` key). No changes to routing, controller, or Mailable class. TDD order: write failing test → update lang files → replace template → verify green.

**Tech Stack:** Hypervel, Blade, PHPUnit (inside Docker: `docker compose exec hypervel`)

---

## File Map

| Action | File |
|--------|------|
| Modify | `tests/Feature/API/V1/Auth/ForgotPasswordControllerTest.php` |
| Modify | `lang/zh_TW/mails.php` |
| Modify | `lang/zh_CN/mails.php` |
| Modify | `lang/en/mails.php` |
| Replace | `resources/views/emails/reset-password.blade.php` |

---

## Task 1: Write failing render test

**Files:**
- Modify: `tests/Feature/API/V1/Auth/ForgotPasswordControllerTest.php`

- [ ] **Step 1: Add render test to existing test class**

Append this method to `ForgotPasswordControllerTest` (before the closing `}`):

```php
public function testResetPasswordMailRendersWithAudistilizerBranding(): void
{
    $tokenUrl = 'http://api.example.com/v1/auth/forgot-password?token=abc&id=1&expires=9999999999&signature=xyz';
    $mailable = new ResetPasswordMail($tokenUrl, 60);
    $html = $mailable->render();

    $this->assertStringContainsString('Audistilizer', $html);
    $this->assertStringContainsString('60', $html);
    $this->assertStringContainsString('If you did not request a password reset', $html);
    $this->assertStringContainsString('/reset-password?token=abc', $html);
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
docker compose exec hypervel vendor/bin/phpunit --filter testResetPasswordMailRendersWithAudistilizerBranding
```

Expected: **FAIL** — old template contains "CatchUp", not "Audistilizer", and lacks `security_note` text.

---

## Task 2: Update lang files

**Files:**
- Modify: `lang/zh_TW/mails.php`
- Modify: `lang/zh_CN/mails.php`
- Modify: `lang/en/mails.php`

- [ ] **Step 1: Replace `lang/zh_TW/mails.php`**

```php
<?php

declare(strict_types=1);

return [
    'reset_password' => [
        'subject'       => '重設您的 Audistilizer 密碼',
        'greeting'      => '您好，',
        'line_1'        => '我們收到了重設 Audistilizer 帳號密碼的請求。若您確認此操作，請點擊下方按鈕來設定新密碼。若您的連結已失效，請重新提出申請。',
        'action'        => '重設密碼',
        'line_2'        => '此連結將於 :count 分鐘後失效',
        'fallback_text' => '如果按鈕無法點擊，請複製以下連結貼到瀏覽器：',
        'security_note' => '如果您沒有要求重設密碼，請忽略此郵件，您的帳號依然安全，密碼不會被更改。Audistilizer 絕不會透過電子郵件要求您提供密碼。',
        'alt_logo'      => 'Audistilizer 標誌',
        'footer_text'   => '&copy; :year Audistilizer Inc.',
    ],
];
```

- [ ] **Step 2: Replace `lang/zh_CN/mails.php`**

```php
<?php

declare(strict_types=1);

return [
    'reset_password' => [
        'subject'       => '重设您的 Audistilizer 密码',
        'greeting'      => '您好，',
        'line_1'        => '我们收到了重设 Audistilizer 账号密码的请求。若您确认此操作，请点击下方按钮设定新密码。若您的链接已失效，请重新提出申请。',
        'action'        => '重设密码',
        'line_2'        => '此链接将于 :count 分钟后失效',
        'fallback_text' => '如果按钮无法点击，请复制以下链接粘贴到浏览器：',
        'security_note' => '如果您没有要求重设密码，请忽略此邮件，您的账号依然安全，密码不会被更改。Audistilizer 绝不会通过电子邮件要求您提供密码。',
        'alt_logo'      => 'Audistilizer 标志',
        'footer_text'   => '&copy; :year Audistilizer Inc.',
    ],
];
```

- [ ] **Step 3: Replace `lang/en/mails.php`**

```php
<?php

declare(strict_types=1);

return [
    'reset_password' => [
        'subject'       => 'Reset Your Audistilizer Password',
        'greeting'      => 'Hello,',
        'line_1'        => 'We received a request to reset the password for your Audistilizer account. Click the button below to set a new password. If your link has expired, please submit a new request.',
        'action'        => 'Reset Password',
        'line_2'        => 'This link expires in :count minutes',
        'fallback_text' => 'If the button does not work, copy and paste the URL below into your browser:',
        'security_note' => 'If you did not request a password reset, you can ignore this email — your account is safe and your password will not be changed. Audistilizer will never ask for your password by email.',
        'alt_logo'      => 'Audistilizer logo',
        'footer_text'   => '&copy; :year Audistilizer Inc.',
    ],
];
```

---

## Task 3: Replace Blade email template

**Files:**
- Replace: `resources/views/emails/reset-password.blade.php`

- [ ] **Step 1: Overwrite the Blade template**

Replace the entire contents of `resources/views/emails/reset-password.blade.php` with:

```blade
<!doctype html>
<html lang="zh-Hant" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<title>{{ __('mails.reset_password.subject') }}</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<![endif]-->
<style>
body, table, td { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; border-collapse:collapse; }
img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
body { margin:0; padding:0; background-color:#f0f4f8; font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f0f4f8;">

<!-- Preheader (hidden preview text) -->
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;visibility:hidden;opacity:0;font-size:1px;color:#f0f4f8;">
  {{ __('mails.reset_password.line_1') }}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
  style="background-color:#f0f4f8;min-width:320px;">
<tbody>
<tr>
  <td align="center" style="padding:48px 20px 64px;">

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"
      style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;">
    <tbody>

    <!-- HEADER: Logo -->
    <tr>
      <td bgcolor="#ffffff"
        style="background-color:#ffffff;padding:28px 40px 22px;border-bottom:1px solid #e2e8f0;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="padding-right:10px;vertical-align:middle;line-height:1;">
              <img src="{{ asset('/logo2.png') }}" width="36" height="36"
                alt="{{ __('mails.reset_password.alt_logo') }}"
                style="display:block;width:36px;height:36px;object-fit:contain;">
            </td>
            <td style="vertical-align:middle;">
              <span style="font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:17px;font-weight:700;color:#1a1a1a;letter-spacing:-0.025em;">Audistilizer</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- HERO: Lock icon + headline + intro -->
    <tr>
      <td bgcolor="#ffffff" style="background-color:#ffffff;padding:44px 40px 0;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
          <tr>
            <td align="center" style="padding-bottom:24px;">
              <!--[if !mso]><!-->
              <div style="display:inline-block;width:64px;height:64px;background-color:#eef2ff;border-radius:14px;text-align:center;line-height:64px;">
                <svg width="28" height="28" viewBox="0 0 28 28" fill="none"
                  xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
                  style="display:inline-block;vertical-align:middle;">
                  <path d="M8 12V8.5a6 6 0 0 1 12 0V12" stroke="#4f46e5" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                  <rect x="4" y="12" width="20" height="13" rx="3" fill="#4f46e5" opacity="0.12"/>
                  <rect x="4" y="12" width="20" height="13" rx="3" stroke="#4f46e5" stroke-width="1.75"/>
                  <circle cx="14" cy="18.5" r="2" fill="#4f46e5"/>
                  <line x1="14" y1="20.5" x2="14" y2="22.5" stroke="#4f46e5" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
              </div>
              <!--<![endif]-->
            </td>
          </tr>
          <tr>
            <td align="center" style="padding-bottom:10px;">
              <h1 style="font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:26px;font-weight:700;color:#1a1a1a;letter-spacing:-0.025em;line-height:1.2;margin:0;padding:0;">
                {{ __('mails.reset_password.action') }}
              </h1>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding-bottom:36px;">
              <p style="font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;color:#64748b;line-height:1.6;margin:0;max-width:400px;display:inline-block;">
                {{ __('mails.reset_password.line_1') }}
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- BODY: Greeting + CTA button + expiry + fallback URL -->
    <tr>
      <td bgcolor="#ffffff" style="background-color:#ffffff;padding:0 40px 28px;">

        <p style="font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;color:#333333;line-height:1.65;margin:0 0 32px 0;">
          {{ __('mails.reset_password.greeting') }}
        </p>

        <!-- CTA Button -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
          <tr>
            <td align="center" style="padding-bottom:20px;">
              <!--[if mso]>
              <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml"
                xmlns:w="urn:schemas-microsoft-com:office:word"
                href="{{ $url }}" style="height:50px;v-text-anchor:middle;width:200px;"
                arcsize="16%" strokecolor="none" fillcolor="#4f46e5">
                <w:anchorlock/>
                <center style="color:#ffffff;font-family:'Segoe UI',Arial,sans-serif;font-size:15px;font-weight:600;">{{ __('mails.reset_password.action') }}</center>
              </v:roundrect>
              <![endif]-->
              <!--[if !mso]><!-->
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                <tr>
                  <td align="center" bgcolor="#4f46e5"
                    style="background-color:#4f46e5;border-radius:8px;">
                    <a href="{{ $url }}"
                      style="display:inline-block;padding:15px 44px;font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;letter-spacing:0.01em;">
                      {{ __('mails.reset_password.action') }}
                    </a>
                  </td>
                </tr>
              </table>
              <!--<![endif]-->
            </td>
          </tr>
        </table>

        <p style="font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#94a3b8;text-align:center;margin:0 0 28px 0;">
          {{ __('mails.reset_password.line_2', ['count' => $minutes]) }}
        </p>

        <!-- Fallback URL box -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
          style="margin-bottom:32px;">
          <tr>
            <td bgcolor="#f8fafc"
              style="background-color:#f8fafc;border-radius:6px;border:1px solid #e2e8f0;padding:16px 20px;">
              <p style="font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;color:#94a3b8;margin:0 0 8px 0;">
                {{ __('mails.reset_password.fallback_text') }}
              </p>
              <p style="font-family:ui-monospace,'Cascadia Code','JetBrains Mono',Menlo,Consolas,'Courier New',monospace;font-size:12px;color:#4f46e5;margin:0;word-break:break-all;line-height:1.55;">
                {{ $url }}
              </p>
            </td>
          </tr>
        </table>

      </td>
    </tr>

    <!-- Security disclaimer -->
    <tr>
      <td bgcolor="#ffffff"
        style="background-color:#ffffff;padding:0 40px 36px;border-top:1px solid #f1f5f9;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
          style="margin-top:24px;">
          <tr>
            <td style="vertical-align:top;width:22px;padding-right:10px;">
              <!--[if !mso]><!-->
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none"
                xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
                style="display:block;margin-top:1px;">
                <path fill-rule="evenodd" clip-rule="evenodd"
                  d="M8 1.5a6.5 6.5 0 100 13 6.5 6.5 0 000-13zM0 8a8 8 0 1116 0A8 8 0 010 8z"
                  fill="#cbd5e1"/>
                <path fill-rule="evenodd" clip-rule="evenodd"
                  d="M8 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 018 5z"
                  fill="#cbd5e1"/>
                <circle cx="8" cy="11.25" r=".875" fill="#cbd5e1"/>
              </svg>
              <!--<![endif]-->
            </td>
            <td>
              <p style="font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#94a3b8;line-height:1.6;margin:0;">
                {{ __('mails.reset_password.security_note') }}
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- FOOTER -->
    <tr>
      <td bgcolor="#f8fafc"
        style="background-color:#f8fafc;padding:24px 40px 28px;border-top:1px solid #e2e8f0;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
          <tr>
            <td align="center" style="padding-bottom:12px;">
              <img src="{{ asset('/logo2.png') }}" width="20" height="20" alt=""
                style="display:inline-block;vertical-align:middle;width:20px;height:20px;object-fit:contain;margin-right:6px;opacity:0.5;">
              <span style="font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;font-weight:600;color:#94a3b8;vertical-align:middle;">Audistilizer</span>
            </td>
          </tr>
          <tr>
            <td align="center">
              <p style="font-family:'Inter',ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;color:#94a3b8;margin:0;">
                {!! __('mails.reset_password.footer_text', ['year' => date('Y')]) !!}
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    </tbody>
    </table>

  </td>
</tr>
</tbody>
</table>

</body>
</html>
```

---

## Task 4: Run tests, fix style, commit

**Files:** (no new files — verification + commit)

- [ ] **Step 1: Run the render test to confirm it now passes**

```bash
docker compose exec hypervel vendor/bin/phpunit --filter testResetPasswordMailRendersWithAudistilizerBranding
```

Expected: **PASS**

- [ ] **Step 2: Run the full forgot-password test suite**

```bash
docker compose exec hypervel vendor/bin/phpunit --filter ForgotPasswordControllerTest
```

Expected: all tests **PASS**

- [ ] **Step 3: Apply cs-fixer to all modified PHP files**

```bash
docker compose exec hypervel composer cs-diff
```

Expected: files reformatted (or "no changes needed"). `cs-diff` auto-detects files changed vs `origin/main`.

- [ ] **Step 4: Commit**

```bash
git add resources/views/emails/reset-password.blade.php \
        lang/zh_TW/mails.php \
        lang/zh_CN/mails.php \
        lang/en/mails.php \
        tests/Feature/API/V1/Auth/ForgotPasswordControllerTest.php

git commit -m "$(cat <<'EOF'
feat(mail): replace reset-password email with Audistilizer design

Swap the basic Blade template for the polished table-based HTML email
from open-design. Updates branding to Audistilizer across zh_TW, zh_CN,
and en lang files; adds security_note translation key; removes unused
salutation/team_name keys.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```
