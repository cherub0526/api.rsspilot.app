<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ __('mails.verify_email.subject', ['code' => $code]) }}</title>
    <style>
        body, table, td { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { border-collapse: collapse; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
    </style>
</head>
<body style="margin:0; padding:0; background:#fbfaf8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fbfaf8;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="max-width:520px; background:#ffffff; border:1px solid #e4e0d9; border-radius:10px;">
                <tr>
                    <td style="padding:32px 32px 8px; font-family:-apple-system,'Segoe UI',Roboto,'PingFang TC',sans-serif; font-size:15px; color:#5f5a54;">
                        {{ __('mails.verify_email.greeting') }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 16px; font-family:-apple-system,'Segoe UI',Roboto,'PingFang TC',sans-serif; font-size:15px; line-height:1.7; color:#5f5a54;">
                        {{ __('mails.verify_email.line_1') }}
                    </td>
                </tr>

                {{-- 驗證碼本體。等寬 + 大字距，讓使用者能一眼逐位抄下來 --}}
                <tr>
                    <td align="center" style="padding:8px 32px 16px;">
                        <div style="display:inline-block; padding:16px 28px; background:#f4f2ee; border:1px solid #e4e0d9; border-radius:6px;
                                    font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:30px; font-weight:700;
                                    letter-spacing:0.35em; color:#1c1a17;">{{ $code }}</div>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 32px 24px; font-family:-apple-system,'Segoe UI',Roboto,'PingFang TC',sans-serif; font-size:13px; color:#7a736a;">
                        {{ __('mails.verify_email.line_2', ['count' => $minutes]) }}
                    </td>
                </tr>

                <tr>
                    <td style="padding:0 32px 12px; font-family:-apple-system,'Segoe UI',Roboto,'PingFang TC',sans-serif; font-size:13px; line-height:1.7; color:#7a736a; border-top:1px solid #e4e0d9; padding-top:20px;">
                        {{ __('mails.verify_email.action_hint') }}
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:4px 32px 20px;">
                        <a href="{{ $url }}"
                           style="display:inline-block; padding:11px 24px; background:#b3450d; color:#ffffff; text-decoration:none;
                                  border-radius:6px; font-family:-apple-system,'Segoe UI',Roboto,'PingFang TC',sans-serif; font-size:14px; font-weight:600;">
                            {{ __('mails.verify_email.action') }}
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 24px; font-family:-apple-system,'Segoe UI',Roboto,'PingFang TC',sans-serif; font-size:12px; line-height:1.6; color:#7a736a; word-break:break-all;">
                        {{ __('mails.verify_email.fallback_text') }}<br>
                        <a href="{{ $url }}" style="color:#b3450d;">{{ $url }}</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 32px 28px; border-top:1px solid #e4e0d9; font-family:-apple-system,'Segoe UI',Roboto,'PingFang TC',sans-serif; font-size:12px; line-height:1.6; color:#7a736a;">
                        {{ __('mails.verify_email.security_note') }}
                    </td>
                </tr>
            </table>
            <div style="padding:16px 0 0; font-family:-apple-system,'Segoe UI',Roboto,'PingFang TC',sans-serif; font-size:11px; color:#7a736a;">
                {!! __('mails.verify_email.footer_text', ['year' => date('Y')]) !!}
            </div>
        </td>
    </tr>
</table>
</body>
</html>
