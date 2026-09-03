<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\User;
use App\Mail\VerifyEmailMail;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Mail;
use App\Models\EmailVerificationCode;
use App\Exceptions\InvalidRequestException;

/**
 * 註冊 email 驗證的發碼與驗碼。
 *
 * 一組憑證同時支撐兩條路徑：使用者手打的 6 位數 code，以及信件連結帶的 token。
 * 兩者是同一筆 row，任一被使用即整筆作廢——這是「用掉一個另一個就失效」的實作處。
 *
 * 錯誤一律用 InvalidRequestException 帶 errorCode 拋出，前端依 code 分支
 * （不比對訊息文字，那是三語系化的）。
 */
class EmailVerificationService
{
    /**
     * 發一組新的驗證碼並寄出。
     *
     * 舊的未使用憑證會先作廢——同時只會有一組有效，避免使用者拿到兩封信之後
     * 用了舊的那組還能過。
     */
    public function issueFor(User $user): EmailVerificationCode
    {
        return DB::transaction(function () use ($user) {
            EmailVerificationCode::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => Carbon::now()]);

            $record = EmailVerificationCode::create([
                'user_id'    => $user->id,
                'code'       => $this->generateCode(),
                'token'      => bin2hex(random_bytes(32)),
                'attempts'   => 0,
                'expires_at' => Carbon::now()->addMinutes(EmailVerificationCode::TTL_MINUTES),
            ]);

            Mail::to($user->email)->send(new VerifyEmailMail(
                $record->code,
                $record->token,
                EmailVerificationCode::TTL_MINUTES
            ));

            return $record;
        });
    }

    /**
     * 重寄。冷卻期間直接擋下——這道限制在後端，前端的倒數只是體驗，
     * 擋不住直接打 API 的人。
     *
     * @throws InvalidRequestException
     */
    public function resendFor(User $user): EmailVerificationCode
    {
        $latest = EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        if ($latest !== null && ($left = $latest->resendCooldownLeft()) > 0) {
            throw InvalidRequestException::withCode(
                'resend_throttled',
                ['retry_after' => $left],
                ['email' => [__('validators.controllers.auth.resend_throttled')]]
            );
        }

        return $this->issueFor($user);
    }

    /**
     * 以 6 位數碼驗證。錯一次就累計一次，達上限即整組作廢。
     *
     * @throws InvalidRequestException
     */
    public function verifyByCode(User $user, string $code): User
    {
        $record = EmailVerificationCode::query()
            ->where('user_id', $user->id)
            ->usable()
            ->orderByDesc('created_at')
            ->first();

        if ($record === null) {
            throw InvalidRequestException::withCode(
                'code_expired',
                [],
                ['code' => [__('validators.controllers.auth.code_expired')]]
            );
        }

        // hash_equals：碼是短字串，時序差異足以被拿來逐位試探
        if (!hash_equals($record->code, $code)) {
            $record->increment('attempts');

            throw InvalidRequestException::withCode(
                'code_invalid',
                ['attempts_left' => $record->fresh()->attemptsLeft()],
                ['code' => [__('validators.controllers.auth.code_invalid')]]
            );
        }

        return $this->consume($record, $user);
    }

    /**
     * 以信件連結的 token 驗證（僅網頁版會走到）。
     *
     * @throws InvalidRequestException
     */
    public function verifyByToken(string $token): User
    {
        $record = EmailVerificationCode::query()
            ->where('token', $token)
            ->usable()
            ->first();

        if ($record === null) {
            throw InvalidRequestException::withCode(
                'code_expired',
                [],
                ['token' => [__('validators.controllers.auth.code_expired')]]
            );
        }

        return $this->consume($record, $record->user);
    }

    /** 標記憑證已用，並把使用者標成已驗證。 */
    private function consume(EmailVerificationCode $record, User $user): User
    {
        DB::transaction(function () use ($record, $user) {
            $record->update(['consumed_at' => Carbon::now()]);

            if ($user->email_verified_at === null) {
                $user->update(['email_verified_at' => Carbon::now()]);
            }
        });

        return $user->refresh();
    }

    /**
     * 6 位純數字。用 random_int 而不是 rand——這是憑證，可預測就等於沒有。
     * 允許前導 0，所以是字串不是整數。
     */
    private function generateCode(): string
    {
        return str_pad(
            (string) random_int(0, 10 ** EmailVerificationCode::CODE_LENGTH - 1),
            EmailVerificationCode::CODE_LENGTH,
            '0',
            STR_PAD_LEFT
        );
    }
}
