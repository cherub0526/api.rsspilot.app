<?php

declare(strict_types=1);

return [
    'controllers' => [
        'auth' => [
            'invalid_credentials' => '電子郵件或密碼無效。',
            'email_unverified'    => '此電子郵件尚未完成驗證。',
            'password_not_set'    => '此帳號是以 Google 建立的，尚未設定密碼。',
            'code_invalid'        => '驗證碼不正確。',
            'code_expired'        => '驗證碼已失效，請重新寄送。',
            'resend_throttled'    => '請稍候再重新寄送驗證碼。',
            'verify_failed'       => '驗證失敗，請稍後再試。',
        ],
        'oauth' => [
            'provider_not_configured' => '該登入方式尚未設定。',
            'exchange_failed'         => '授權驗證失敗，請重新登入。',
        ],
        'custom_prompts' => [
            'plan_required'  => '自訂 AI 摘要需要 Pro 以上的方案。',
            'preview_failed' => '試跑失敗，請稍後再試或調整提示內容。',
        ],
        'media' => [
            'not_found'           => '找不到指定的媒體。',
            'caption_not_found'   => '找不到指定的字幕。',
            'invalid_url'         => '無效的 YouTube 影片網址。',
            'video_limit_reached' => '已達到方案允許的影片數量上限。',
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
                    'unique'   => '此電子郵件已被註冊。',
        ],
        'code' => [
            'required' => '請輸入驗證碼。',
            'digits'   => '驗證碼必須是 6 位數字。',
        ],
        'token' => [
            'required' => '缺少驗證憑證。',
            'string'   => '驗證憑證格式不正確。',
        ],
        'password' => [
            'required'  => '密碼為必填。',
            'string'    => '密碼必須是字串。',
            'min'       => '密碼長度至少需要 8 個字元。',
            'max'       => '密碼長度不能超過 64 個字元。',
            'regex'     => '密碼必須包含至少一個大寫英文字母、一個小寫英文字母與一個特殊符號。',
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
            'max'     => '限制不能超過 12。',
        ],
        'url' => [
            'required' => '網址為必填。',
            'string'   => '網址必須是字串。',
        ],
    ],
    'custom_prompts' => [
        'media_id' => [
            'required' => '請選擇要試跑的影片。',
            'size'     => '影片 ID 格式不正確。',
        ],
        'title' => [
            'required' => '標題為必填。',
            'string'   => '標題必須是字串。',
            'max'      => '標題不得超過 :max 個字元。',
        ],
        'content' => [
            'required' => '內容為必填。',
            'string'   => '內容必須是字串。',
            'max'      => '內容不得超過 :max 個字元。',
        ],
        'model_id' => [
            'size' => '模型 ID 格式不正確。',
        ],
        'source_ids' => [
            'array' => '套用來源必須是陣列。',
            'max'   => '套用來源最多 :max 個。',
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
        'redirect' => [
            'required' => '導轉網址為必填。',
            'string'   => '導轉網址必須是字串。',
            'url'      => '導轉網址格式不正確。',
            'max'      => '導轉網址不得超過 :max 個字元。',
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
        'locale' => [
            'required' => '請選擇介面語系。',
            'in'       => '選擇的介面語系不支援。',
        ],
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
                'required' => 'text.long_summary 欄位為必填項。',
                'array'    => 'text.long_summary 必須是陣列。',
                'content'  => [
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
