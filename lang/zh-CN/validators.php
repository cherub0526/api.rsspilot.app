<?php

declare(strict_types=1);

return [
    'controllers' => [
        'auth' => [
            'invalid_credentials' => '帐号或密码无效。',
        ],
        'media' => [
            'not_found'          => '找不到指定的媒体。',
            'caption_not_found'  => '找不到指定的字幕。',
            'invalid_url'        => '无效的 YouTube 影片网址。',
            'video_limit_reached' => '已达到方案允许的影片数量上限。',
        ],
        'chat' => [
            'chat_limit_reached' => '已达到方案允许的每日 AI 对话上限。',
        ],
        'rss' => [
            'invalid_url' => '无效的 RSS 网址。',
            'not_found'   => '找不到指定的 RSS。',
        ],
        'sources' => [
            'invalid_url'           => '无效的 YouTube 网址。',
            'not_found'             => '找不到指定的来源。',
            'channel_limit_reached' => '已达到方案允许的频道订阅上限。',
        ],
        'subscription' => [
            'plan_not_found'      => '找不到指定的方案。',
            'price_not_found'     => '找不到指定的价格。',
            'price_not_in_plan'   => '方案中找不到指定的价格。',
            'not_found'           => '找不到指定的订阅。',
            'session_id_required' => 'session_id 为必填。',
        ],
        'webhook' => [
            'paddle' => [
                'transaction_not_completed' => '交易状态未完成。',
            ],
        ],
    ],
    'auth' => [
        'account' => [
            'required' => '帐号为必填。',
            'string'   => '帐号必须是字符串。',
            'min'      => '帐号长度至少需要 6 个字符。',
            'max'      => '帐号长度不能超过 255 个字符。',
            'unique'   => '帐号已存在。',
        ],
        'email' => [
            'required' => '电子邮建为必填。',
            'email'    => '电子邮建格式无效。',
            'max'      => '电子邮建长度不能超过 255 个字符。',
        ],
        'password' => [
            'required'  => '密码为必填。',
            'string'    => '密码必须是字符串。',
            'min'       => '密码长度至少需要 8 个字符。',
            'max'       => '密码长度不能超过 64 个字符。',
            'regex'     => '密码必须包含至少一个大写英文字母、一个小写英文字母与一个特殊符号。',
            'confirmed' => '确认密码不相符。',
        ],
        'password_confirmation' => [
            'required' => '确认密码为必填。',
            'string'   => '确认密码必须是字符串。',
        ],
    ],
    'chat' => [
        'session_id' => [
            'invalid' => 'Session ID 格式不正确。',
        ],
        'messages' => [
            'required' => '讯息为必填。',
            'array'    => '讯息必须是数组。',
            'min'      => '讯息至少需要 1 个项目。',
            'role'     => [
                'required' => '角色为必填。',
                'string'   => '角色必须是字符串。',
                'in'       => '选择的角色无效。',
            ],
            'content' => [
                'required' => '内容为必填。',
                'string'   => '内容必须是字符串。',
            ],
        ],
    ],
    'media' => [
        'type' => [
            'required' => '类型为必填。',
            'string'   => '类型必须是字符串。',
            'in'       => '类型无效。',
        ],
        'limit' => [
            'integer' => '限制必须是整数。',
            'min'     => '限制至少为 1。',
            'max'     => '限制不能超过 12。',
        ],
        'url' => [
            'required' => '网址为必填。',
            'string'   => '网址必须是字符串。',
        ],
    ],
    'oauth' => [
        'provider' => [
            'required' => '提供者为必填。',
            'in'       => '提供者无效。',
        ],
        'code' => [
            'required' => '代码为必填。',
        ],
    ],
    'rss' => [
        'type' => [
            'required' => '类型为必填。',
            'string'   => '类型必须是字符串。',
            'in'       => '类型无效。',
        ],
        'url' => [
            'required' => 'URL 为必填。',
        ],
    ],
    'source' => [
        'url' => [
            'required' => 'URL 为必填。',
        ],
        'type' => [
            'required' => '类型为必填。',
            'in'       => '类型必须是 channel 或 playlist。',
        ],
        'notify' => [
            'required' => '通知设置为必填。',
            'boolean'  => '通知设置必须是布尔值。',
        ],
    ],
    'subscription' => [
        'planId' => [
            'required' => '方案 ID 为必填。',
            'string'   => '方案 ID 必须是字符串。',
        ],
        'priceId' => [
            'required' => '价格 ID 为必填。',
            'string'   => '价格 ID 必须是字符串。',
        ],
        'paymentMethod' => [
            'in' => '付款方式必须是 stripe 或 paddle。',
        ],
    ],
    'user' => [
        'name' => [
            'required' => '名称为必填。',
            'string'   => '名称必须是字符串。',
        ],
        'email' => [
            'required' => '电子邮建为必填。',
            'email'    => '电子邮建格式无效。',
        ],
    ],

    'groq' => [
        'status' => [
            'required' => '状态字段为必填。',
        ],
        'data' => [
            'required' => '数据字段为必填。',
            'language' => [
                'required' => '语言字段为必填。',
            ],
            'duration' => [
                'required' => '时长字段为必填。',
            ],
            'text' => [
                'required' => '文本字段为必填。',
            ],
            'words' => [
                'required' => '单词字段为必填。',
            ],
            'segments' => [
                'required' => '片段字段为必填。',
            ],
            'error' => [
                'required' => '错误信息为必填。',
            ],
        ],
    ],

    'paddle' => [
        'event_id' => [
            'required' => '事件 ID 为必填项。',
        ],
        'event_type' => [
            'required' => '事件类型为必填项。',
            'in'       => '无效的事件类型。',
        ],
        'occurred_at' => [
            'required' => '发生时间为必填项。',
        ],
        'notification_id' => [
            'required' => '通知 ID 为必填项。',
        ],
        'data' => [
            'required' => '数据字段为必填项。',
            'id'       => [
                'required' => '数据 ID 为必填项。',
            ],
        ],
    ],

    'settings' => [
        'locale' => [
            'required' => '请选择界面语系。',
            'in'       => '选择的界面语系不支持。',
        ],
        'ai' => [
            'required' => 'ai 字段为必填项。',
            'language' => [
                'required' => 'ai.language 字段为必填项。',
                'in'       => '所选的 ai.language 无效。',
            ],
        ],
    ],

    'youtube_mp3_downloader' => [
        'status' => [
            'required' => 'status 字段为必填项。',
            'in'       => '所选的 status 无效。',
        ],
        'data' => [
            'status' => [
                'required' => 'data.status 字段为必填项。',
                'in'       => '所选的 data.status 无效。',
            ],
            'link' => [
                'required'   => 'data.link 字段为必填项。',
                'active_url' => 'data.link 不是有效的可访问网址。',
            ],
        ],
    ],

    'summary' => [
        'locale' => [
            'required' => 'locale 字段为必填项。',
            'string'   => 'locale 必须是字符串。',
        ],
        'text' => [
            'required'      => 'text 字段为必填项。',
            'array'         => 'text 必须是数组。',
            'short_summary' => [
                'required' => 'text.short_summary 字段为必填项。',
                'string'   => 'text.short_summary 必须是字符串。',
            ],
            'long_summary' => [
                'required'   => 'text.long_summary 字段为必填项。',
                'array'      => 'text.long_summary 必须是数组。',
                'content'    => [
                    'required' => 'text.long_summary.content 字段为必填项。',
                    'string'   => 'text.long_summary.content 必须是字符串。',
                ],
                'key_points' => [
                    'required' => 'text.long_summary.key_points 字段为必填项。',
                    'array'    => 'text.long_summary.key_points 必须是数组。',
                ],
                'keywords' => [
                    'required' => 'text.long_summary.keywords 字段为必填项。',
                    'array'    => 'text.long_summary.keywords 必须是数组。',
                ],
            ],
        ],
    ],
];
