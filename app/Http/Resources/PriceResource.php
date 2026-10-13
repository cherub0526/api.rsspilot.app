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
        ];
    }
}
