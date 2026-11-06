<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubmissionController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequireCustomer;
use App\Http\Middleware\RequireSpaSession;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireSpaSession::class)->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

});

Route::middleware(['auth:sanctum', RequireSpaSession::class, EnsureActiveUser::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::middleware(RequireCustomer::class)->group(function () {
        Route::post('/applications/{application}/submissions', [SubmissionController::class, 'store'])->whereNumber('application');
        Route::get('/applications/{application}/submissions', [SubmissionController::class, 'index'])->whereNumber('application');
        Route::get('/applications/{application}/submissions/{submission}', [SubmissionController::class, 'show'])->whereNumber(['application', 'submission']);
        Route::get('/applications/{application}/status-histories', [SubmissionController::class, 'histories'])->whereNumber('application');
        Route::get('/document-types', [DocumentController::class, 'types']);
        Route::get('/applications/{application}/documents', [DocumentController::class, 'index'])->whereNumber('application');
        Route::post('/applications/{application}/documents', [DocumentController::class, 'store'])->whereNumber('application');
        Route::get('/document-versions/{document}/download', [DocumentController::class, 'download'])->whereNumber('document');
        Route::get('/customer/profile', [ProfileController::class, 'show']);
        Route::patch('/customer/profile', [ProfileController::class, 'update']);
        Route::get('/applications', [ApplicationController::class, 'index']);
        Route::post('/applications', [ApplicationController::class, 'store']);
        Route::get('/applications/{application}', [ApplicationController::class, 'show'])->whereNumber('application');
        Route::patch('/applications/{application}', [ApplicationController::class, 'update'])->whereNumber('application');
        Route::delete('/applications/{application}', [ApplicationController::class, 'destroy'])->whereNumber('application');
    });
});
