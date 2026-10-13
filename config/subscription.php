<?php

declare(strict_types=1);

return [
    /*
     * 新帳號的影片額度加碼。
     *
     * 免費方案每 30 天只有 3 支，目標客群一週就會看超過 3 支——他們在喜歡上
     * 產品之前就撞到牆。所以帳號建立後的前幾天把影片上限墊高，讓人先用到有感，
     * 加碼結束才回到方案本身的額度。
     *
     * limit 是**下限而不是取代**：生效上限是 max(方案額度, limit)，所以首週就
     * 升級到 Pro（20 支）的人不會反而被這個值壓低。方案額度為 0（不限制）時
     * 整個加碼不適用。
     *
     * days = 0 或 limit = 0 就等於關閉加碼，行為回到純方案額度。
     */
    'video_boost' => [
        'days'  => (int) env('SUBSCRIPTION_VIDEO_BOOST_DAYS', 7),
        'limit' => (int) env('SUBSCRIPTION_VIDEO_BOOST_LIMIT', 10),
    ],
];
