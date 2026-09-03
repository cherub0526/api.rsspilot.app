<?php

declare(strict_types=1);

namespace App\Mail;

use Hypervel\Mail\Mailable;
use Hypervel\Queue\Queueable;
use Hypervel\Queue\SerializesModels;

/**
 * 註冊後的 email 驗證信。
 *
 * 同時放 6 位數碼與一條連結，兩者是同一組憑證（用掉一個另一個即失效）。
 * 連結只對網頁版有意義——桌面版收不到外部瀏覽器的驗證結果，所以信裡明講
 * 桌面版請改用驗證碼。見前端 repo 的 docs/auth-email-migration.md 決定 6。
 */
class VerifyEmailMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $code,
        public string $token,
        public int $minutes
    ) {
    }

    public function build(): self
    {
        // 與 ResetPasswordMail 同樣以 CLIENT_URL 為基底；落點是前端路由，不是 API。
        $verifyUrl = rtrim((string) env('CLIENT_URL'), '/') . '/auth/verify?token=' . urlencode($this->token);

        return $this->subject(__('mails.verify_email.subject', ['code' => $this->code]))
            ->view('emails.verify-email', [
                'code'    => $this->code,
                'url'     => $verifyUrl,
                'minutes' => $this->minutes,
            ]);
    }
}
