<?php

declare(strict_types=1);

namespace App\Validators;

use App\Http\Controllers\API\V1\Webhook\PaddleController;

class PaddleTransactionValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->messages = [
            'event_id.required'        => __('validators.paddle.event_id.required'),
            'event_type.required'      => __('validators.paddle.event_type.required'),
            'event_type.in'            => __('validators.paddle.event_type.in'),
            'occurred_at.required'     => __('validators.paddle.occurred_at.required'),
            'notification_id.required' => __('validators.paddle.notification_id.required'),
            'data.required'            => __('validators.paddle.data.required'),
            'data.id.required'         => __('validators.paddle.data.id.required'),
        ];
    }

    public function setStoreRules(): self
    {
        // event_type 白名單的事實來源在 controller，避免「訂閱了事件卻在驗證層
        // 被擋掉」這種只有上線後才看得到的落差。
        $this->rules = [
            'event_id'        => 'required',
            'event_type'      => 'required|in:' . implode(',', PaddleController::HANDLED_EVENTS),
            'occurred_at'     => 'required',
            'notification_id' => 'required',
            'data'            => 'required',
            'data.id'         => 'required',
        ];

        return $this;
    }
}
