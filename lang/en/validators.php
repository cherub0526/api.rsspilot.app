<?php

declare(strict_types=1);

return [
    'controllers' => [
        'auth' => [
            'invalid_credentials' => 'Invalid account or password.',
        ],
        'media' => [
            'not_found'         => 'Media not found.',
            'caption_not_found' => 'Caption not found.',
        ],
        'rss' => [
            'invalid_url' => 'Invalid RSS URL.',
            'not_found'   => 'RSS not found.',
        ],
        'sources' => [
            'invalid_url' => 'Invalid YouTube URL.',
            'not_found'   => 'Source not found.',
        ],
        'subscription' => [
            'plan_not_found'    => 'Plan not found.',
            'price_not_found'   => 'Price not found.',
            'price_not_in_plan' => 'Price not found in plan.',
            'not_found'         => 'Subscription not found.',
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
            'max'     => 'Limit must not exceed 10.',
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
];
