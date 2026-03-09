<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BudgetsController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\FundingSourcesController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\SubscriptionsController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebhookIntegrationsController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('token.auth')->group(function (): void {
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::post('/integrations/qris/email', [WebhookIntegrationsController::class, 'ingestQrisEmail']);

Route::middleware('token.auth')->group(function (): void {
    Route::patch('/users/name', [UsersController::class, 'updateName']);
    Route::put('/users/profile', [UsersController::class, 'updateProfile']);
    Route::patch('/users/password', [UsersController::class, 'resetPassword']);

    Route::get('/categories', [CategoriesController::class, 'index']);
    Route::get('/categories/suggest', [CategoriesController::class, 'suggest']);
    Route::post('/categories', [CategoriesController::class, 'store']);
    Route::get('/categories/{id}', [CategoriesController::class, 'show']);
    Route::put('/categories/{id}', [CategoriesController::class, 'update']);
    Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

    Route::get('/funding-sources', [FundingSourcesController::class, 'index']);
    Route::post('/funding-sources', [FundingSourcesController::class, 'store']);
    Route::put('/funding-sources/{id}', [FundingSourcesController::class, 'update']);
    Route::delete('/funding-sources/{id}', [FundingSourcesController::class, 'destroy']);

    Route::get('/transactions', [TransactionsController::class, 'index']);
    Route::post('/transactions', [TransactionsController::class, 'store']);
    Route::get('/transactions/month/{year}/{month}', [TransactionsController::class, 'byMonth']);
    Route::get('/transactions/{id}', [TransactionsController::class, 'show']);
    Route::put('/transactions/{id}', [TransactionsController::class, 'update']);
    Route::delete('/transactions/{id}', [TransactionsController::class, 'destroy']);

    Route::get('/budgets', [BudgetsController::class, 'index']);
    Route::post('/budgets', [BudgetsController::class, 'store']);
    Route::get('/budgets/filter', [BudgetsController::class, 'filter']);
    Route::get('/budgets/goals', [BudgetsController::class, 'goals']);
    Route::get('/budgets/predictive', [BudgetsController::class, 'predictive']);
    Route::get('/budgets/{id}', [BudgetsController::class, 'show']);
    Route::put('/budgets/{id}', [BudgetsController::class, 'update']);
    Route::delete('/budgets/{id}', [BudgetsController::class, 'destroy']);
    Route::get('/budgets/{id}/usage', [BudgetsController::class, 'usage']);

    Route::get('/notifications', [NotificationsController::class, 'index']);
    Route::get('/notifications/unread', [NotificationsController::class, 'unread']);
    Route::get('/notifications/unread/count', [NotificationsController::class, 'unreadCount']);
    Route::patch('/notifications/{id}/read', [NotificationsController::class, 'markRead']);
    Route::patch('/notifications/read-all', [NotificationsController::class, 'markAllRead']);

    Route::get('/insights/assistant', [InsightsController::class, 'assistant']);
    Route::post('/insights/receipt-ocr', [InsightsController::class, 'receiptOcr']);
    Route::get('/subscriptions/dashboard', [SubscriptionsController::class, 'dashboard']);
});
