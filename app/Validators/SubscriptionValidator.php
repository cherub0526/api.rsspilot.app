<?php

declare(strict_types=1);

namespace App\Validators;

use App\Models\Subscription;

class SubscriptionValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->messages = [
            'planId.required'   => __('validators.subscription.planId.required'),
            'planId.string'     => __('validators.subscription.planId.string'),
            'priceId.required'  => __('validators.subscription.priceId.required'),
            'priceId.string'    => __('validators.subscription.priceId.string'),
            'paymentMethod.in'  => __('validators.subscription.paymentMethod.in'),
            'accessCode.string' => __('validators.subscription.accessCode.string'),
            'accessCode.max'    => __('validators.subscription.accessCode.max'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'planId'        => 'required|string',
            'priceId'       => 'required|string',
            'paymentMethod' => 'sometimes|string|in:' . implode(',', Subscription::checkoutPaymentMethods()),
            // 隱藏方案的連結碼。只擋型別與長度（不讓陣列或超長字串進到比對），
            // 碼對不對由 Controller 判斷，錯了回的是 plan_not_found，不是這裡的訊息。
            'accessCode' => 'nullable|string|max:64',
        ];

        return $this;
    }
}
