<?php

use think\facade\Route;

Route::group('client/region', function () {
    Route::get('children', 'children');
    Route::get('path/:id', 'path');
})->prefix('region.RegionController/');
