<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['data' => ['name' => 'Lendara'], 'message' => 'API Lendara. Buka antarmuka di origin frontend.']);
});
