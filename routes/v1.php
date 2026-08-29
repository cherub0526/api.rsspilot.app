<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use App\Http\Controllers\API\V1\RSSController;
use App\Http\Controllers\API\V1\AuthController;
use App\Http\Controllers\API\V1\MediaController;
use App\Http\Controllers\API\V1\PlansController;
use App\Http\Controllers\API\V1\UsersController;
use App\Http\Controllers\API\V1\SourcesController;
use App\Http\Controllers\API\V1\SettingsController;
use App\Http\Controllers\API\V1\FeedbacksController;
use App\Http\Controllers\API\V1\Media\ChatController;
use App\Http\Controllers\API\V1\PopulariesController;
use App\Http\Controllers\API\V1\Auth\GoogleController;
use App\Http\Controllers\API\V1\Auth\LogoutController;
use App\Http\Controllers\API\V1\Auth\RefreshController;
use App\Http\Controllers\API\V1\Users\AvatarController;
use App\Http\Controllers\API\V1\Webhook\GroqController;
use App\Http\Controllers\API\V1\Auth\RegisterController;
use App\Http\Controllers\API\V1\CustomPromptsController;
use App\Http\Controllers\API\V1\SubscriptionsController;
use App\Http\Controllers\API\V1\Media\CaptionsController;
use App\Http\Controllers\API\V1\Oauth\CallbackController;
use App\Http\Controllers\API\V1\Oauth\RedirectController;
use App\Http\Controllers\API\V1\Webhook\PaddleController;
use App\Http\Controllers\API\V1\Webhook\StripeController;
use App\Http\Controllers\API\V1\Media\SummariesController;
use App\Http\Controllers\API\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\API\V1\Webhook\YoutubeMp3DownloaderController;
use App\Http\Controllers\API\V1\Subscriptions\CheckoutSessionController;
use App\Http\Controllers\API\V1\Sources\MediasController as SourceMediasController;
use App\Http\Controllers\API\V1\Users\SessionsController as UserSessionsController;
use App\Http\Controllers\API\V1\Media\Chat\StreamController as ChatStreamController;
use App\Http\Controllers\API\V1\Media\Chat\SessionsController as ChatSessionsController;
use App\Http\Controllers\API\V1\Media\Chat\FollowUpsController as ChatFollowUpsController;
use App\Http\Controllers\API\V1\Subscriptions\UsageController as SubscriptionUsageController;

Route::group('/feedbacks', function () {
    Route::post('/', [
        'as'   => 'store',
        'uses' => FeedbacksController::class . '@store',
    ]);
}, ['as' => 'feedbacks', 'middleware' => ['auth']]);

Route::group('/auth', function () {
    Route::post(
        '/forgot-password',
        [
            'as'   => 'forgot-password.store',
            'uses' => ForgotPasswordController::class . '@store',
        ]
    );

    Route::post('/google', [
        'as'   => 'google.store',
        'uses' => GoogleController::class . '@store',
    ]);

    Route::put(
        'forgot-password',
        [
            'as'   => 'forgot-password.update',
            'uses' => ForgotPasswordController::class . '@update',
        ]
    );

    Route::post(
        '/register',
        ['as' => 'register.store', 'uses' => RegisterController::class . '@store']
    );

    Route::post('/', ['as' => 'store', 'uses' => AuthController::class . '@store']);

    Route::post(
        '/refresh',
        ['as' => 'refresh.store', 'uses' => RefreshController::class . '@store', 'middleware' => ['auth']]
    );
    Route::post(
        '/logout',
        [
            'as'         => 'logout.store',
            'uses'       => LogoutController::class . '@store',
            'middleware' => ['auth'],
        ]
    );
}, ['as' => 'auth']);

// 登入流程的起點，必然是未登入狀態，不掛 auth。
Route::group('/oauth', function () {
    Route::post('/{provider}/redirect', [
        'as'   => 'redirect.store',
        'uses' => RedirectController::class . '@store',
    ]);
    Route::post('/{provider}/callback', [
        'as'   => 'callback.store',
        'uses' => CallbackController::class . '@store',
    ]);
}, ['as' => 'oauth']);

Route::group('/custom-prompts', function () {
    Route::get('/', ['as' => 'index', 'uses' => CustomPromptsController::class . '@index']);
    Route::post('/', ['as' => 'store', 'uses' => CustomPromptsController::class . '@store']);
    Route::delete('/{promptId:[0-9]+}', [
        'as'   => 'destroy',
        'uses' => CustomPromptsController::class . '@destroy',
    ]);
}, ['as' => 'custom-prompts', 'middleware' => ['auth']]);

Route::group('/users', function () {
    Route::get(
        '/',
        [
            'as'         => 'index',
            'uses'       => UsersController::class . '@index',
            'middleware' => ['auth'],
        ]
    );

    Route::put('/', [
        'as'         => 'update',
        'uses'       => UsersController::class . '@update',
        'middleware' => ['auth'],
    ]);

    Route::post('/avatar', [
        'as'         => 'avatar.store',
        'uses'       => AvatarController::class . '@store',
        'middleware' => ['auth'],
    ]);

    Route::get('/sessions', [
        'as'         => 'sessions.index',
        'uses'       => UserSessionsController::class . '@index',
        'middleware' => ['auth'],
    ]);

    Route::delete('/sessions', [
        'as'         => 'sessions.destroy',
        'uses'       => UserSessionsController::class . '@destroy',
        'middleware' => ['auth'],
    ]);
}, ['as' => 'users']);

Route::group('/settings', function () {
    Route::put(
        '/',
        [
            'as'         => 'update',
            'uses'       => SettingsController::class . '@update',
            'middleware' => ['auth'],
        ]
    );
}, ['as' => 'settings']);

Route::group('/rss', function () {
    Route::get(
        '/',
        [
            'as'         => 'index',
            'uses'       => RSSController::class . '@index',
            'middleware' => ['auth'],
        ]
    );
    Route::post(
        '/',
        [
            'as'         => 'store',
            'uses'       => RSSController::class . '@store',
            'middleware' => ['auth'],
        ]
    );
    Route::delete('/{rssId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [
        'as'         => 'destroy',
        'uses'       => RSSController::class . '@destroy',
        'middleware' => ['auth'],
    ]);
}, ['as' => 'rss']);

Route::group('/popularies', function () {
    Route::get('/', [
        'as'         => 'index',
        'uses'       => PopulariesController::class . '@index',
        'middleware' => ['auth'],
    ]);
}, ['as' => 'popularies']);

Route::group('/sources', function () {
    Route::get('/', [
        'as'         => 'index',
        'uses'       => SourcesController::class . '@index',
        'middleware' => ['auth'],
    ]);
    Route::post('/', [
        'as'         => 'store',
        'uses'       => SourcesController::class . '@store',
        'middleware' => ['auth'],
    ]);
    Route::get('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [
        'as'         => 'show',
        'uses'       => SourcesController::class . '@show',
        'middleware' => ['auth'],
    ]);
    Route::put('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [
        'as'         => 'update',
        'uses'       => SourcesController::class . '@update',
        'middleware' => ['auth'],
    ]);
    Route::delete('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [
        'as'         => 'destroy',
        'uses'       => SourcesController::class . '@destroy',
        'middleware' => ['auth'],
    ]);

    Route::group('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}/medias', function () {
        Route::get('/', [
            'as'         => 'index',
            'uses'       => SourceMediasController::class . '@index',
            'middleware' => ['auth'],
        ]);
    }, ['as' => 'medias']);
}, ['as' => 'sources']);

Route::group('/media', function () {
    Route::get(
        '/',
        [
            'as'         => 'index',
            'uses'       => MediaController::class . '@index',
            'middleware' => ['auth'],
        ]
    );
    Route::post(
        '/',
        [
            'as'         => 'store',
            'uses'       => MediaController::class . '@store',
            'middleware' => ['auth'],
        ]
    );
    Route::get(
        '/{mediaId:[0-7][0-9a-hjkmnp-tv-z]{25}}',
        [
            'as'         => 'show',
            'uses'       => MediaController::class . '@show',
            'middleware' => ['auth'],
        ]
    );

    Route::group('/{mediaId:[0-7][0-9a-hjkmnp-tv-z]{25}}/summaries', function () {
        Route::get(
            '/',
            [
                'as'         => 'index',
                'uses'       => SummariesController::class . '@index',
                'middleware' => ['auth'],
            ]
        );
        Route::get(
            '/{summaryId:[0-7][0-9a-hjkmnp-tv-z]{25}}',
            [
                'as'         => 'show',
                'uses'       => SummariesController::class . '@show',
                'middleware' => ['auth'],
            ]
        );
    }, ['as' => 'summaries']);

    Route::group('/{mediaId:[0-7][0-9a-hjkmnp-tv-z]{25}}/captions', function () {
        Route::get(
            '/',
            [
                'as'         => 'index',
                'uses'       => CaptionsController::class . '@index',
                'middleware' => ['auth'],
            ]
        );
        Route::get(
            '/{captionId}',
            [
                'as'         => 'show',
                'uses'       => CaptionsController::class . '@show',
                'middleware' => ['auth'],
            ]
        );
    }, ['as' => 'captions']);

    Route::group('/{mediaId:[0-7][0-9a-hjkmnp-tv-z]{25}}/chat', function () {
        Route::post('/', [
            'as'         => 'store',
            'uses'       => ChatController::class . '@store',
            'middleware' => ['auth'],
        ]);
        Route::get('/stream', [
            'as'         => 'stream.show',
            'uses'       => ChatStreamController::class . '@show',
            'middleware' => ['auth'],
        ]);
        Route::get('/sessions', [
            'as'         => 'sessions.index',
            'uses'       => ChatSessionsController::class . '@index',
            'middleware' => ['auth'],
        ]);
        Route::get(
            '/sessions/{sessionId:[0-7][0-9a-hjkmnp-tv-z]{25}}',
            [
                'as'         => 'sessions.show',
                'uses'       => ChatSessionsController::class . '@show',
                'middleware' => ['auth'],
            ]
        );
        Route::delete(
            '/sessions/{sessionId:[0-7][0-9a-hjkmnp-tv-z]{25}}',
            [
                'as'         => 'sessions.destroy',
                'uses'       => ChatSessionsController::class . '@destroy',
                'middleware' => ['auth'],
            ]
        );
        // 每次呼叫都是一次真實的推論，而且刻意不計入每日 chat 額度，
        // 所以成本的上限改由 throttle 擋：每位使用者每分鐘 10 次。
        Route::get(
            '/sessions/{sessionId:[0-7][0-9a-hjkmnp-tv-z]{25}}/follow-ups',
            [
                'as'         => 'sessions.follow-ups.show',
                'uses'       => ChatFollowUpsController::class . '@show',
                'middleware' => ['auth', 'throttle:10,1'],
            ]
        );
    }, ['as' => 'chat']);
}, ['as' => 'media']);

Route::group('/subscriptions', function () {
    Route::get(
        '/',
        [
            'as'         => 'index',
            'uses'       => SubscriptionsController::class . '@index',
            'middleware' => ['auth'],
        ]
    );
    Route::post('/', [
        'as'         => 'store',
        'uses'       => SubscriptionsController::class . '@store',
        'middleware' => ['auth'],
    ]);
    // 靜態段排在同層動態段之前。`{subscriptionId}` 沒有格式約束，目前只掛
    // PUT/DELETE 所以還撞不到這兩條 GET——但只要哪天補上 GET /{subscriptionId}，
    // 順序就是唯一的保障，不要等到那時候才調。
    Route::get('/checkout-session', [
        'as'         => 'checkout-session.index',
        'uses'       => CheckoutSessionController::class . '@index',
        'middleware' => ['auth'],
    ]);

    Route::get('/usage', [
        'as'         => 'usage.index',
        'uses'       => SubscriptionUsageController::class . '@index',
        'middleware' => ['auth'],
    ]);

    Route::put('/{subscriptionId}', [
        'as'         => 'update',
        'uses'       => SubscriptionsController::class . '@update',
        'middleware' => ['auth'],
    ]);
    Route::delete('/{subscriptionId}', [
        'as'         => 'destroy',
        'uses'       => SubscriptionsController::class . '@destroy',
        'middleware' => ['auth'],
    ]);
}, ['as' => 'subscriptions']);

Route::group('/plans', function () {
    Route::get(
        '/',
        [
            'as'   => 'index',
            'uses' => PlansController::class . '@index',
        ]
    );
}, ['as' => 'plans']);

Route::group('/webhook', function () {
    Route::post(
        '/paddle',
        [
            'as'   => 'paddle.store',
            'uses' => PaddleController::class . '@store',
        ]
    );

    Route::post(
        '/stripe',
        [
            'as'   => 'stripe.store',
            'uses' => StripeController::class . '@store',
        ]
    );

    Route::post(
        '/youtube-mp3-downloader/{mediaId}',
        [
            'as'   => 'youtube-mp3-downloader.store',
            'uses' => YoutubeMp3DownloaderController::class . '@store',
        ]
    );

    Route::post(
        '/summaries/{mediaId}',
        [
            'as'   => 'summaries.store',
            'uses' => App\Http\Controllers\API\V1\Webhook\SummariesController::class . '@store',
        ]
    );

    Route::post(
        '/groq/{mediaId}',
        [
            'as'   => 'groq.store',
            'uses' => GroqController::class . '@store',
        ]
    );
}, ['as' => 'webhook']);
