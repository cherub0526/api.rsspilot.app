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
