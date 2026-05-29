<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use App\Http\Controllers\API\V1\RSSController;
use App\Http\Controllers\API\V1\AuthController;
use App\Http\Controllers\API\V1\MediaController;
use App\Http\Controllers\API\V1\UsersController;
use App\Http\Controllers\API\V1\SourcesController;
use App\Http\Controllers\API\V1\SettingsController;
use App\Http\Controllers\API\V1\FeedbacksController;
use App\Http\Controllers\API\V1\Media\ChatController;
use App\Http\Controllers\API\V1\PopulariesController;
use App\Http\Controllers\API\V1\Auth\GoogleController;
use App\Http\Controllers\API\V1\Users\AvatarController;
use App\Http\Controllers\API\V1\Webhook\GroqController;
use App\Http\Controllers\API\V1\SubscriptionsController;
use App\Http\Controllers\API\V1\Media\CaptionsController;
use App\Http\Controllers\API\V1\Webhook\PaddleController;
use App\Http\Controllers\API\V1\Media\SummariesController;
use App\Http\Controllers\API\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\API\V1\Subscriptions\PlansController;
use App\Http\Controllers\API\V1\Webhook\YoutubeMp3DownloaderController;
use App\Http\Controllers\API\V1\Sources\MediasController as SourceMediasController;
use App\Http\Controllers\API\V1\Users\SessionsController as UserSessionsController;
use App\Http\Controllers\API\V1\Media\Chat\StreamController as ChatStreamController;
use App\Http\Controllers\API\V1\Media\Chat\SessionsController as ChatSessionsController;

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
        ['as' => 'register', 'uses' => AuthController::class . '@register']
    );

    Route::post('/', ['as' => 'store', 'uses' => AuthController::class . '@store']);

    Route::post(
        '/refresh',
        ['as' => 'refresh', 'uses' => AuthController::class . '@refresh', 'middleware' => ['auth']]
    );
    Route::post(
        '/logout',
        [
            'as'         => 'logout',
            'uses'       => AuthController::class . '@logout',
            'middleware' => ['auth'],
        ]
    );
}, ['as' => 'auth']);

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
            'as'         => 'stream',
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

    Route::get('/usage', [
        'as'         => 'usage',
        'uses'       => SubscriptionsController::class . '@usage',
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
