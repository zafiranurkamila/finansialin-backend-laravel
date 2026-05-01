<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BudgetsController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SubscriptionsController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebhookIntegrationsController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\ResourceController;
use Illuminate\Support\Facades\Route;

Route::get('/documentation', function () {
    return view('swagger');
});

Route::get('/openapi.yaml', function () {
    return response()->file(base_path('openapi.yaml'));
});

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/register/verify', [AuthController::class, 'verifyRegister']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/2fa/verify-login', [AuthController::class, 'verifyLoginTwoFactor'])->middleware('token.2fa.pending');

    Route::middleware('token.auth')->group(function (): void {
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/email/verification/send', [SecurityController::class, 'sendEmailVerification']);
        Route::post('/email/verification/verify', [SecurityController::class, 'verifyEmail']);

        Route::post('/2fa/enable', [SecurityController::class, 'enableTwoFactor']);
        Route::post('/2fa/enable/verify', [SecurityController::class, 'verifyEnableTwoFactor']);
        Route::post('/2fa/disable', [SecurityController::class, 'disableTwoFactor']);
    });
});

Route::post('/integrations/qris/email', [WebhookIntegrationsController::class, 'ingestQrisEmail']);

// Internal API — dipanggil oleh Python AI service (tanpa token auth)
Route::get('/internal/balance', [\App\Http\Controllers\ChatbotController::class, 'internalGetBalance']);

Route::middleware('token.auth')->group(function (): void {
    Route::patch('/users/name', [UsersController::class, 'updateName']);
    Route::put('/users/profile', [UsersController::class, 'updateProfile']);
    Route::patch('/users/password', [UsersController::class, 'resetPassword']);
    Route::get('/users/preferences', [PreferencesController::class, 'show']);
    Route::put('/users/preferences', [PreferencesController::class, 'update']);

    Route::get('/categories', [CategoriesController::class, 'index']);
    Route::get('/categories/suggest', [CategoriesController::class, 'suggest']);
    Route::post('/categories', [CategoriesController::class, 'store']);
    Route::get('/categories/{id}', [CategoriesController::class, 'show']);
    Route::put('/categories/{id}', [CategoriesController::class, 'update']);
    Route::delete('/categories/{id}', [CategoriesController::class, 'destroy']);

    Route::get('/transactions', [TransactionsController::class, 'index']);
    Route::get('/transactions/search', [TransactionsController::class, 'search']);
    Route::post('/transactions', [TransactionsController::class, 'store']);
    Route::get('/transactions/month/{year}/{month}', [TransactionsController::class, 'byMonth']);
    Route::get('/transactions/{id}', [TransactionsController::class, 'show']);
    Route::put('/transactions/{id}', [TransactionsController::class, 'update']);
    Route::delete('/transactions/{id}', [TransactionsController::class, 'destroy']);

    Route::get('/budgets', [BudgetsController::class, 'index']);
    Route::post('/budgets', [BudgetsController::class, 'store']);
    Route::post('/budgets/income-split', [BudgetsController::class, 'incomeSplit']);
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

    Route::get('/insights/assistant', [ChatbotController::class, 'assistant']);
    Route::get('/insights/dashboard-summary', [ChatbotController::class, 'dashboardSummary']);
    Route::post('/insights/receipt-ocr', [ChatbotController::class, 'receiptOcr']);
    Route::post('/insights/predict-early-warning', [ChatbotController::class, 'predictEarlyWarning']);
    Route::post('/insights/chat', [ChatbotController::class, 'chat']);
    Route::get('/subscriptions/dashboard', [SubscriptionsController::class, 'dashboard']);

    Route::get('/salaries/summary/overview', [SalaryController::class, 'summary']);
    Route::get('/salaries', [SalaryController::class, 'index']);
    Route::post('/salaries', [SalaryController::class, 'store']);
    Route::get('/salaries/{id}', [SalaryController::class, 'show']);
    Route::put('/salaries/{id}', [SalaryController::class, 'update']);
    Route::post('/salaries/{id}/receive', [SalaryController::class, 'receive']);
    Route::post('/salaries/{id}/cancel', [SalaryController::class, 'cancel']);
    Route::delete('/salaries/{id}', [SalaryController::class, 'destroy']);

    Route::get('/resources/summary', [ResourceController::class, 'summary']);
    Route::get('/resources', [ResourceController::class, 'index']);
    Route::get('/resources/{idResource}', [ResourceController::class, 'show']);
    
    Route::post('/chat', [ChatbotController::class, 'chat']);
});
