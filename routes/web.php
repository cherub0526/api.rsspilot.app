<?php

declare(strict_types=1);

use App\Services\YoutubeService;

Route::get('yt', function () {
    $service = new YoutubeService();
    $response = $service->getVideoDetails('ojttMNOW6zM');
    dd($response);
});
Route::get('/test', function () {
    //    $request = request();
    //    $request->merge(['prompt' => '影片中出現了哪些食物？']);
    //
    //    auth()->guard()->login(User::find('01kccjjhb03spptxmkzhq3gpry'));
    //
    //    $media = $request->user()->media()->whereHas('captions')->first();
    //
    //    $c = app(CustomPromptController::class);
    //    return $c->store($request, $media->id);
});
