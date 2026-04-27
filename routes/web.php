<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/documentation', function () {
    return view('swagger');
});

Route::get('/openapi.yaml', function () {
    return response()->file(base_path('openapi.yaml'));
});
