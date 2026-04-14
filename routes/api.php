<?php

use App\Http\Controllers\Api\V1\Admin\AiPlaygroundController;
use App\Http\Controllers\Api\V1\Admin\AiUsageController;
use App\Http\Controllers\Api\V1\Admin\BillingSettingsController;
use App\Http\Controllers\Api\V1\Admin\CampaignBuilderLimitController;
use App\Http\Controllers\Api\V1\Admin\DebugController;
use App\Http\Controllers\Api\V1\Admin\DeviceManagementController;
use App\Http\Controllers\Api\V1\Admin\EsimInventoryController;
use App\Http\Controllers\Api\V1\Admin\OrderProvisionController;
use App\Http\Controllers\Api\V1\Admin\PhoneNumberAssignmentController;
use App\Http\Controllers\Api\V1\Admin\UserManagementController;
use App\Http\Controllers\Api\V1\AndroidGatewayController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\EsimController;
use App\Http\Controllers\Api\V1\UserDeviceController;
use App\Http\Controllers\Api\V1\NowPaymentsWebhookController;
use App\Http\Controllers\Api\V1\NumberPurchaseController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PhoneNumberMarketplaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/webhooks/nowpayments', NowPaymentsWebhookController::class);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware(['role:admin'])->prefix('admin')->group(function (): void {
            Route::get('/users', [UserManagementController::class, 'index']);
            Route::post('/users', [UserManagementController::class, 'store']);
            Route::put('/users/{user}', [UserManagementController::class, 'update']);
            Route::delete('/users/{user}', [UserManagementController::class, 'destroy']);
            Route::post('/users/{user}/reset-password', [UserManagementController::class, 'resetPassword']);
            Route::post('/users/{user}/toggle-status', [UserManagementController::class, 'toggleStatus']);
            Route::get('/devices', [DeviceManagementController::class, 'index']);
            Route::get('/devices/{device}', [DeviceManagementController::class, 'show']);
            Route::post('/devices', [DeviceManagementController::class, 'store']);
            Route::patch('/devices/{device}', [DeviceManagementController::class, 'update']);
            Route::patch('/devices/{device}/owner', [DeviceManagementController::class, 'updateOwner']);
            Route::delete('/devices/{device}', [DeviceManagementController::class, 'destroy']);
            Route::post('/devices/registration-token', [DeviceManagementController::class, 'issueRegistrationToken']);
            Route::post('/devices/registration-otp', [DeviceManagementController::class, 'issueRegistrationOtp']);
            Route::post('/device/token/issue', [DeviceManagementController::class, 'issueRegistrationToken']);
            Route::get('/phone-numbers', [DeviceManagementController::class, 'phoneNumbers']);
            Route::get('/phone-numbers/carriers', [PhoneNumberAssignmentController::class, 'carrierNames']);
            Route::get('/phone-numbers/assignments', [PhoneNumberAssignmentController::class, 'index']);
            Route::post('/phone-numbers/assign', [PhoneNumberAssignmentController::class, 'assign']);
            Route::patch('/phone-numbers/{phoneNumber}', [PhoneNumberAssignmentController::class, 'update']);
            Route::get('/phone-numbers/{phoneNumber}/delete-impact', [PhoneNumberAssignmentController::class, 'deleteImpact']);
            Route::delete('/phone-numbers/{phoneNumber}', [PhoneNumberAssignmentController::class, 'destroy']);
            Route::post('/phone-numbers/{phoneNumber}/unassign', [PhoneNumberAssignmentController::class, 'unassign']);
            Route::get('/campaign-builder-limits', [CampaignBuilderLimitController::class, 'show']);
            Route::put('/campaign-builder-limits', [CampaignBuilderLimitController::class, 'update']);
            Route::get('/billing/settings', [BillingSettingsController::class, 'show']);
            Route::put('/billing/settings', [BillingSettingsController::class, 'update']);
            Route::get('/users/{user}/phone-pricing', [BillingSettingsController::class, 'showUserPrice']);
            Route::put('/users/{user}/phone-pricing', [BillingSettingsController::class, 'updateUserPrice']);
            Route::get('/debug/messages/by-device-message-id/{deviceMessageId}', [DebugController::class, 'messageByDeviceMessageId'])
                ->whereNumber('deviceMessageId');
            Route::get('/debug/messages/{message}', [DebugController::class, 'messageById']);
            Route::post('/ai/chat', [AiPlaygroundController::class, 'chat']);
            Route::get('/ai-usage', [AiUsageController::class, 'index']);
            Route::get('/esims', [EsimInventoryController::class, 'index']);
            Route::post('/esims', [EsimInventoryController::class, 'store']);
            Route::patch('/esims/{esim}', [EsimInventoryController::class, 'update']);
            Route::post('/esims/import', [EsimInventoryController::class, 'import']);
            Route::post('/orders/provision/device-slots', [OrderProvisionController::class, 'provisionDeviceSlot']);
            Route::post('/orders/provision/esim', [OrderProvisionController::class, 'provisionEsim']);
        });

        Route::middleware(['permission:campaign'])->group(function (): void {
            Route::get('/campaigns/builder-limits', [CampaignController::class, 'builderLimits']);
            Route::get('/campaigns/assignable-phone-numbers', [CampaignController::class, 'assignablePhoneNumbers']);
            Route::get('/campaigns', [CampaignController::class, 'index']);
            Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
            Route::post('/campaigns', [CampaignController::class, 'store']);
            Route::put('/campaigns/{campaign}', [CampaignController::class, 'update']);
            Route::patch('/campaigns/{campaign}/status', [CampaignController::class, 'updateStatus']);
            Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy']);
            Route::post('/campaigns/{campaign}/steps', [CampaignController::class, 'addStep']);
            Route::post('/campaigns/{campaign}/phone-numbers/{phoneNumber}', [CampaignController::class, 'assignNumber']);
        });

        Route::middleware(['permission:chat'])->group(function (): void {
            Route::get('/chat/conversations', [ChatController::class, 'conversations']);
            Route::get('/chat/numbers', [ChatController::class, 'numbers']);
            Route::post('/chat/send', [ChatController::class, 'send']);
            Route::delete('/chat/conversations/{conversation}', [ChatController::class, 'destroy']);
        });

        Route::get('/numbers/my', [NumberPurchaseController::class, 'myNumbers']);
        Route::get('/numbers/catalog', [PhoneNumberMarketplaceController::class, 'catalog']);
        Route::get('/numbers/pricing-preview', [PhoneNumberMarketplaceController::class, 'pricingPreview']);
        Route::get('/numbers/orders', [PhoneNumberMarketplaceController::class, 'myOrders']);
        Route::post('/numbers/orders', [PhoneNumberMarketplaceController::class, 'store']);
        Route::post('/numbers/purchase', [NumberPurchaseController::class, 'purchase']);
        Route::post('/numbers/{phoneNumber}/renew', [NumberPurchaseController::class, 'renew']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/catalog', [OrderController::class, 'catalog']);
        Route::get('/orders/pricing-preview', [OrderController::class, 'pricingPreview']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::middleware(['permission:device'])->group(function (): void {
            Route::get('/devices/my', [UserDeviceController::class, 'myDevices']);
            Route::post('/devices/claim-code', [UserDeviceController::class, 'issueDeviceClaimCode']);
        });
        Route::get('/esim/catalog', [EsimController::class, 'catalog']);
        Route::get('/esim/my', [EsimController::class, 'myEsims']);
        Route::post('/esim/{userEsim}/reveal', [EsimController::class, 'reveal']);
    });
});

// Android compatibility aliases (kept unversioned intentionally)
Route::post('/device/register', [AndroidGatewayController::class, 'register']);
Route::post('/device/status', [AndroidGatewayController::class, 'status']);
Route::post('/device/heartbeat', [AndroidGatewayController::class, 'heartbeat']);
Route::post('/sms/receive', [AndroidGatewayController::class, 'receiveSms']);
Route::post('/sms/update-status', [AndroidGatewayController::class, 'updateStatus']);
Route::post('/sms/mms-received', [AndroidGatewayController::class, 'mmsReceived']);
Route::post('/sms/mms-attachment', [AndroidGatewayController::class, 'mmsAttachment']);
