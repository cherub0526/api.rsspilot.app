<?php

declare(strict_types=1);

return [
    'controllers' => [
        'auth' => [
            'invalid_credentials' => 'Invalid account or password.',
        ],
        'oauth' => [
            'provider_not_configured' => 'This sign-in method is not configured yet.',
            'exchange_failed'         => 'Authorization failed, please sign in again.',
        ],
        'custom_prompts' => [
            'plan_required'  => 'Custom AI summaries require a Pro plan or above.',
            'preview_failed' => 'The test run failed, please retry or adjust the prompt.',
        ],
        'media' => [
            'not_found'           => 'Media not found.',
            'caption_not_found'   => 'Caption not found.',
            'invalid_url'         => 'Invalid YouTube video URL.',
            'video_limit_reached' => 'You have reached the video limit for your plan.',
        ],
        'chat' => [
            'chat_limit_reached' => 'You have reached the daily AI chat limit for your plan.',
        ],
        'rss' => [
            'invalid_url' => 'Invalid RSS URL.',
            'not_found'   => 'RSS not found.',
        ],
        'sources' => [
            'invalid_url'           => 'Invalid YouTube URL.',
            'not_found'             => 'Source not found.',
            'channel_limit_reached' => 'You have reached the channel subscription limit for your plan.',
        ],
        'subscription' => [
            'plan_not_found'      => 'Plan not found.',
            'price_not_found'     => 'Price not found.',
            'price_not_in_plan'   => 'Price not found in plan.',
            'not_found'           => 'Subscription not found.',
            'session_id_required' => 'session_id is required.',
        ],
        'webhook' => [
            'paddle' => [
                'transaction_not_completed' => 'Transaction status is not completed.',
            ],
        ],
    ],
    'auth' => [
        'account' => [
            'required' => 'Account is required.',
            'string'   => 'Account must be a string.',
            'min'      => 'Account must be at least 6 characters.',
            'max'      => 'Account must not exceed 255 characters.',
            'unique'   => 'Account already exists.',
        ],
        'email' => [
            'required' => 'Email is required.',
            'email'    => 'Email format is invalid.',
            'max'      => 'Email must not exceed 255 characters.',
        ],
        'password' => [
            'required'  => 'Password is required.',
            'string'    => 'Password must be a string.',
            'min'       => 'Password must be at least 8 characters.',
            'max'       => 'Password must not exceed 64 characters.',
            'regex'     => 'Password must contain at least one uppercase letter, one lowercase letter, and one special character.',
            'confirmed' => 'Password confirmation does not match.',
        ],
        'password_confirmation' => [
            'required' => 'Password confirmation is required.',
            'string'   => 'Password confirmation must be a string.',
        ],
    ],
    'chat' => [
        'session_id' => [
            'invalid' => 'The session ID format is invalid.',
        ],
        'messages' => [
            'required' => 'The messages field is required.',
            'array'    => 'The messages must be an array.',
            'min'      => 'The messages must have at least 1 item.',
            'role'     => [
                'required' => 'The role field is required.',
                'string'   => 'The role must be a string.',
                'in'       => 'The selected role is invalid.',
            ],
            'content' => [
                'required' => 'The content field is required.',
                'string'   => 'The content must be a string.',
            ],
        ],
    ],
    'media' => [
        'type' => [
            'required' => 'Type is required.',
            'string'   => 'Type must be a string.',
            'in'       => 'Type is invalid.',
        ],
        'limit' => [
            'integer' => 'Limit must be an integer.',
            'min'     => 'Limit must be at least 1.',
            'max'     => 'Limit must not exceed 12.',
        ],
        'url' => [
            'required' => 'URL is required.',
            'string'   => 'URL must be a string.',
        ],
    ],
    'custom_prompts' => [
        'media_id' => [
            'required' => 'Please pick a video to test with.',
            'size'     => 'The video ID format is invalid.',
        ],
        'title' => [
            'required' => 'Title is required.',
            'string'   => 'Title must be a string.',
            'max'      => 'Title may not be greater than :max characters.',
        ],
        'content' => [
            'required' => 'Content is required.',
            'string'   => 'Content must be a string.',
            'max'      => 'Content may not be greater than :max characters.',
        ],
        'model_id' => [
            'size' => 'The model ID format is invalid.',
        ],
        'source_ids' => [
            'array' => 'Applied sources must be an array.',
            'max'   => 'You may apply at most :max sources.',
        ],
    ],
    'oauth' => [
        'provider' => [
            'required' => 'Provider is required.',
            'in'       => 'Provider is invalid.',
        ],
        'code' => [
            'required' => 'Code is required.',
        ],
        'redirect' => [
            'required' => 'Redirect URL is required.',
            'string'   => 'Redirect URL must be a string.',
            'url'      => 'Redirect URL is invalid.',
            'max'      => 'Redirect URL may not be greater than :max characters.',
        ],
    ],
    'rss' => [
        'type' => [
            'required' => 'Type is required.',
            'string'   => 'Type must be a string.',
            'in'       => 'Type is invalid.',
        ],
        'url' => [
            'required' => 'URL is required.',
        ],
    ],
    'source' => [
        'url' => [
            'required' => 'URL is required.',
        ],
        'type' => [
            'required' => 'Type is required.',
            'in'       => 'Type must be channel or playlist.',
        ],
        'notify' => [
            'required' => 'Notify setting is required.',
            'boolean'  => 'Notify setting must be a boolean.',
        ],
    ],
    'subscription' => [
        'planId' => [
            'required' => 'Plan ID is required.',
            'string'   => 'Plan ID must be a string.',
        ],
        'priceId' => [
            'required' => 'Price ID is required.',
            'string'   => 'Price ID must be a string.',
        ],
        'paymentMethod' => [
            'in' => 'Payment method must be stripe or paddle.',
        ],
    ],
    'user' => [
        'name' => [
            'required' => 'Name is required.',
            'string'   => 'Name must be a string.',
        ],
        'email' => [
            'required' => 'Email is required.',
            'email'    => 'Email format is invalid.',
        ],
    ],

    'groq' => [
        'status' => [
            'required' => 'The status field is required.',
        ],
        'data' => [
            'required' => 'The data field is required.',
            'language' => [
                'required' => 'The language field is required.',
            ],
            'duration' => [
                'required' => 'The duration field is required.',
            ],
            'text' => [
                'required' => 'The text field is required.',
            ],
            'words' => [
                'required' => 'The words field is required.',
            ],
            'segments' => [
                'required' => 'The segments field is required.',
            ],
            'error' => [
                'required' => 'The error message is required.',
            ],
        ],
    ],

    'paddle' => [
        'event_id' => [
            'required' => 'The event ID is required.',
        ],
        'event_type' => [
            'required' => 'The event type is required.',
            'in'       => 'The selected event type is invalid.',
        ],
        'occurred_at' => [
            'required' => 'The occurred at timestamp is required.',
        ],
        'notification_id' => [
            'required' => 'The notification ID is required.',
        ],
        'data' => [
            'required' => 'The data payload is required.',
            'id'       => [
                'required' => 'The data ID is required.',
            ],
        ],
    ],

    'settings' => [
        'locale' => [
            'required' => 'The locale field is required.',
            'in'       => 'The selected locale is invalid.',
        ],
        'ai' => [
            'required' => 'The ai field is required.',
            'language' => [
                'required' => 'The ai.language field is required.',
                'in'       => 'The selected ai.language is invalid.',
            ],
        ],
    ],

    'youtube_mp3_downloader' => [
        'status' => [
            'required' => 'The status field is required.',
            'in'       => 'The selected status is invalid.',
        ],
        'data' => [
            'status' => [
                'required' => 'The data.status field is required.',
                'in'       => 'The selected data.status is invalid.',
            ],
            'link' => [
                'required'   => 'The data.link field is required.',
                'active_url' => 'The data.link is not a valid active URL.',
            ],
        ],
    ],

    'summary' => [
        'locale' => [
            'required' => 'The locale field is required.',
            'string'   => 'The locale must be a string.',
        ],
        'text' => [
            'required'      => 'The text field is required.',
            'array'         => 'The text must be an array.',
            'short_summary' => [
                'required' => 'The text.short_summary field is required.',
                'string'   => 'The text.short_summary must be a string.',
            ],
            'long_summary' => [
                'required' => 'The text.long_summary field is required.',
                'array'    => 'The text.long_summary must be an array.',
                'content'  => [
                    'required' => 'The text.long_summary.content field is required.',
                    'string'   => 'The text.long_summary.content must be a string.',
                ],
                'key_points' => [
                    'required' => 'The text.long_summary.key_points field is required.',
                    'array'    => 'The text.long_summary.key_points must be an array.',
                ],
                'keywords' => [
                    'required' => 'The text.long_summary.keywords field is required.',
                    'array'    => 'The text.long_summary.keywords must be an array.',
                ],
            ],
        ],
    ],
];
