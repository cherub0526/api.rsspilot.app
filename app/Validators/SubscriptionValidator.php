<?php

declare(strict_types=1);

namespace App\Validators;

class SubscriptionValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->messages = [
            'planId.required'         => __('validators.subscription.planId.required'),
            'planId.string'           => __('validators.subscription.planId.string'),
            'priceId.required'        => __('validators.subscription.priceId.required'),
            'priceId.string'          => __('validators.subscription.priceId.string'),
            'paymentMethod.in'        => __('validators.subscription.paymentMethod.in'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'planId'        => 'required|string',
            'priceId'       => 'required|string',
            'paymentMethod' => 'sometimes|string|in:stripe,paddle',
        ];

        return $this;
    }
}
