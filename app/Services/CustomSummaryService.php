<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\AiModel;
use App\Models\CustomPrompt;
use App\Utils\Const\ISO6391;

/**
 * 自訂 AI 摘要在「背景產生」這條路上要用的判斷。
 *
 * 試跑（PreviewController）也做同樣的判斷，但它靠 Request 取使用者方案
 * （ResolvesUserPlan），排程與 queue job 手上沒有 Request，所以這裡以 User 為準
 * 另外包一份。兩邊的規則必須一致：試跑看到的就是之後真的會存下來的。
 */
class CustomSummaryService
{
    public function planFor(User $user): ?Plan
    {
        $service = app(SubscriptionService::class);

        return $service->getUserSubscriptionPlan(
            $service->getUserSubscription((string) $user->getKey())
        );
    }

    /** 方案目前是否開放自訂摘要。降級或到期後就停止產生，不溯及已存的摘要。 */
    public function allowsCustomSummary(User $user): bool
    {
        $plan = $this->planFor($user);

        return $plan !== null && (bool) $plan->getAttribute('custom_summary_enabled');
    }

    /**
     * 使用者選的模型換成供應商代號；沒選、停用或方案沒授權的回空字串，
     * 由 TemplateCompletionManager 依模板查系統預設。規則同 ResolvesUserPlan::allowedModelId()。
     */
    public function providerModelFor(User $user, ?string $modelId): string
    {
        if ($modelId === null || $modelId === '') {
            return '';
        }

        $plan = $this->planFor($user);

        if ($plan === null) {
            return '';
        }

        return (string) (AiModel::query()
            ->where('enabled', true)
            ->whereKey($modelId)
            ->whereHas('plans', fn ($query) => $query->whereKey($plan->getKey()))
            ->value('provider_model') ?? '');
    }

    /**
     * 這支影片要套用這位使用者的哪一個提示詞。
     *
     * 同一個來源可以被同一人的多個提示詞綁定，後端沒有擋；這時取**最近更新**的那個，
     * 讓結果可預期——使用者剛改過的設定就是他想要的設定。
     */
    public function promptFor(User $user, Media $media): ?CustomPrompt
    {
        if (!$media->source_id) {
            return null;
        }

        return CustomPrompt::query()
            ->where('user_id', $user->getKey())
            ->whereHas('sources', fn ($query) => $query->whereKey($media->source_id))
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * 主字幕全文；還沒有字幕時回 null。
     *
     * 與試跑用同一份輸入（PreviewController::captionsOf），試跑的結果才會等於實際
     * 存下來的摘要。
     */
    public function captionsOf(Media $media): ?string
    {
        $text = (string) ($media->captions()->orderByDesc('primary')->first()->text ?? '');

        return trim($text) === '' ? null : $text;
    }

    /**
     * 自訂摘要存在哪個 locale。
     *
     * 要對上 Media::summaryFor() 的挑選條件（user_id + 使用者的 UI 語系），否則寫得進
     * 去卻讀不出來。使用者沒設語系時 summaryFor 不看 locale，這時記字幕的語言。
     */
    public function localeFor(User $user, Media $media): string
    {
        $locale = $user->uiLocale();

        if ($locale !== null) {
            return ISO6391::normalize($locale);
        }

        $caption = $media->captions()->orderByDesc('primary')->first();

        return ISO6391::normalize((string) ($caption->locale ?? 'en'));
    }
}
