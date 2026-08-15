<?php

declare(strict_types=1);

return [
    'controllers' => [
        'auth' => [
            'invalid_credentials' => '帳號或密碼無效。',
        ],
        'media' => [
            'not_found'         => '找不到指定的媒體。',
            'caption_not_found' => '找不到指定的字幕。',
        ],
        'chat' => [
            'chat_limit_reached' => '已達到方案允許的每日 AI 對話上限。',
        ],
        'rss' => [
            'invalid_url' => '無效的 RSS 網址。',
            'not_found'   => '找不到指定的 RSS。',
        ],
        'sources' => [
            'invalid_url'           => '無效的 YouTube 網址。',
            'not_found'             => '找不到指定的來源。',
            'channel_limit_reached' => '已達到方案允許的頻道訂閱上限。',
        ],
        'subscription' => [
            'plan_not_found'      => '找不到指定的方案。',
            'price_not_found'     => '找不到指定的價格。',
            'price_not_in_plan'   => '方案中找不到指定的價格。',
            'not_found'           => '找不到指定的訂閱。',
            'session_id_required' => 'session_id 為必填。',
        ],
        'webhook' => [
            'paddle' => [
                'transaction_not_completed' => '交易狀態未完成。',
            ],
        ],
    ],
    'auth' => [
        'account' => [
            'required' => '帳號為必填。',
            'string'   => '帳號必須是字串。',
            'min'      => '帳號長度至少需要 6 個字元。',
            'max'      => '帳號長度不能超過 255 個字元。',
            'unique'   => '帳號已存在。',
        ],
        'email' => [
            'required' => '電子郵件為必填。',
            'email'    => '電子郵件格式無效。',
            'max'      => '電子郵件長度不能超過 255 個字元。',
        ],
        'password' => [
            'required'  => '密碼為必填。',
            'string'    => '密碼必須是字串。',
            'min'       => '密碼長度至少需要 8 個字元。',
            'confirmed' => '確認密碼不相符。',
        ],
        'password_confirmation' => [
            'required' => '確認密碼為必填。',
            'string'   => '確認密碼必須是字串。',
        ],
    ],
    'chat' => [
        'session_id' => [
            'invalid' => 'Session ID 格式不正確。',
        ],
        'messages' => [
            'required' => '訊息為必填。',
            'array'    => '訊息必須是陣列。',
            'min'      => '訊息至少需要 1 個項目。',
            'role'     => [
                'required' => '角色為必填。',
                'string'   => '角色必須是字串。',
                'in'       => '選擇的角色無效。',
            ],
            'content' => [
                'required' => '內容為必填。',
                'string'   => '內容必須是字串。',
            ],
        ],
    ],
    'media' => [
        'type' => [
            'required' => '類型為必填。',
            'string'   => '類型必須是字串。',
            'in'       => '類型無效。',
        ],
        'limit' => [
            'integer' => '限制必須是整數。',
            'min'     => '限制至少為 1。',
            'max'     => '限制不能超過 10。',
        ],
    ],
    'oauth' => [
        'provider' => [
            'required' => '提供者為必填。',
            'in'       => '提供者無效。',
        ],
        'code' => [
            'required' => '代碼為必填。',
        ],
    ],
    'rss' => [
        'type' => [
            'required' => '類型為必填。',
            'string'   => '類型必須是字串。',
            'in'       => '類型無效。',
        ],
        'url' => [
            'required' => 'URL 為必填。',
        ],
    ],
    'source' => [
        'url' => [
            'required' => 'URL 為必填。',
        ],
        'type' => [
            'required' => '類型為必填。',
            'in'       => '類型必須是 channel 或 playlist。',
        ],
        'notify' => [
            'required' => '通知設定為必填。',
            'boolean'  => '通知設定必須是布林值。',
        ],
    ],
    'subscription' => [
        'planId' => [
            'required' => '方案 ID 為必填。',
            'string'   => '方案 ID 必須是字串。',
        ],
        'priceId' => [
            'required' => '價格 ID 為必填。',
            'string'   => '價格 ID 必須是字串。',
        ],
        'paymentMethod' => [
            'in' => '付款方式必須是 stripe 或 paddle。',
        ],
    ],
    'user' => [
        'name' => [
            'required' => '名稱為必填。',
            'string'   => '名稱必須是字串。',
        ],
        'email' => [
            'required' => '電子郵件為必填。',
            'email'    => '電子郵件格式無效。',
        ],
    ],

    'groq' => [
        'status' => [
            'required' => '狀態欄位為必填。',
        ],
        'data' => [
            'required' => '資料欄位為必填。',
            'language' => [
                'required' => '語言欄位為必填。',
            ],
            'duration' => [
                'required' => '時長欄位為必填。',
            ],
            'text' => [
                'required' => '文本欄位為必填。',
            ],
            'words' => [
                'required' => '字詞欄位為必填。',
            ],
            'segments' => [
                'required' => '片段欄位為必填。',
            ],
            'error' => [
                'required' => '錯誤訊息為必填。',
            ],
        ],
    ],
    'paddle' => [
        'event_id' => [
            'required' => '事件 ID 為必填項。',
        ],
        'event_type' => [
            'required' => '事件類型為必填項。',
            'in'       => '無效的事件類型。',
        ],
        'occurred_at' => [
            'required' => '發生時間為必填項。',
        ],
        'notification_id' => [
            'required' => '通知 ID 為必填項。',
        ],
        'data' => [
            'required' => '資料欄位為必填項。',
            'id'       => [
                'required' => '資料 ID 為必填項。',
            ],
        ],
    ],

    'settings' => [
        'ai' => [
            'required' => 'ai 欄位為必填項。',
            'language' => [
                'required' => 'ai.language 欄位為必填項。',
                'in'       => '所選的 ai.language 無效。',
            ],
        ],
    ],

    'youtube_mp3_downloader' => [
        'status' => [
            'required' => 'status 欄位為必填項。',
            'in'       => '所選的 status 無效。',
        ],
        'data' => [
            'status' => [
                'required' => 'data.status 欄位為必填項。',
                'in'       => '所選的 data.status 無效。',
            ],
            'link' => [
                'required'   => 'data.link 欄位為必填項。',
                'active_url' => 'data.link 不是有效的可存取網址。',
            ],
        ],
    ],

    'summary' => [
        'locale' => [
            'required' => 'locale 欄位為必填項。',
            'string'   => 'locale 必須是字串。',
        ],
        'text' => [
            'required'      => 'text 欄位為必填項。',
            'array'         => 'text 必須是陣列。',
            'short_summary' => [
                'required' => 'text.short_summary 欄位為必填項。',
                'string'   => 'text.short_summary 必須是字串。',
            ],
            'long_summary' => [
                'required'   => 'text.long_summary 欄位為必填項。',
                'array'      => 'text.long_summary 必須是陣列。',
                'content'    => [
                    'required' => 'text.long_summary.content 欄位為必填項。',
                    'string'   => 'text.long_summary.content 必須是字串。',
                ],
                'key_points' => [
                    'required' => 'text.long_summary.key_points 欄位為必填項。',
                    'array'    => 'text.long_summary.key_points 必須是陣列。',
                ],
                'keywords' => [
                    'required' => 'text.long_summary.keywords 欄位為必填項。',
                    'array'    => 'text.long_summary.keywords 必須是陣列。',
                ],
            ],
        ],
    ],
];
