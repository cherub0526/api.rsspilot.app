<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class PriceResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id'    => strval($this->resource->id),
            'unit'  => strval($this->resource->unit),
            'price' => floatval($this->resource->price),
            // 首期優惠價（年繳首年），null = 沒有優惠。續約一律收 `price`。
            //
            // 走 firstPeriodPrice() 而不是直接吐欄位：那支會把「>= 原價」或
            // 「<= 0」這種填錯的值當成沒有優惠。定價頁顯示什麼與結帳實際折多少
            // 必須是同一個判斷，不然頁面會承諾一個收不到的價格。
            'first_period_price' => $this->resource->firstPeriodPrice(),
            // 免費試用幾天；null = 這個 price 不送試用。
            //
            // **給出去是為了讓對外文案不必自己寫死天數。** 試用長度是後端的
            // `Subscription::TRIAL_DAYS`，而定價頁與結帳 modal 都要寫出它——寫死
            // 在語系檔裡的話，改天數要改四個地方（三家金流 + 三語系文案），而漏掉
            // 文案那一邊不會有任何東西報錯，只會讓我們承諾一個不是真的的天數。
            'trial_days' => $this->resource->trialDays(),
        ];
    }
}
