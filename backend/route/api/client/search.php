<?php

use think\facade\Route;

Route::group('search', function () {
    Route::get('hot', 'hot');
    Route::post('log', 'log');
})->prefix('client.search.SearchController/');
