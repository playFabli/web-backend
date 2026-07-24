<?php

use App\Http\Controllers\Admin\GeneralController;
use App\Http\Controllers\User\AuthController;
use App\Http\Controllers\User\QuestController;
use App\Http\Controllers\User\SettingsController;
use App\Http\Controllers\User\TradeController;
use App\Http\Middleware\RouteGuardOnlyAdmin;
use App\Http\Middleware\RouteGuardOnlyAuthenticated;
use App\Http\Middleware\RouteGuardOnlyGuest;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get("/email-verification", function() {
    $code = request()->query("code", null);
    $instance = EmailVerificationCode::where("code", $code)->first();
    if(!$instance) return;

    $user = User::where("id", $instance->user_id)->first();
    if(!$user) return;
    else if($user && $user->is_email_verified) {
        return redirect("https://playfabli.com/user/homepage");
    } else {
        $user->is_email_verified = true;
        $user->save();

        return redirect("https://playfabli.com/user/homepage");
    }
});

Route::group(['prefix' => 'auth', 'middleware' => [RouteGuardOnlyGuest::class]], function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::group(['prefix' => 'admin', 'middleware' => [RouteGuardOnlyAdmin::class]], function () {
    Route::get('/dashboard', [GeneralController::class, 'dashboard']);

    // Users
    Route::get('/users', [GeneralController::class, 'users']);
    Route::get('/users/{id}', [GeneralController::class, 'user']);
    Route::post('/users/{id}', [GeneralController::class, 'updateUser']);
    Route::delete('/users/{id}', [GeneralController::class, 'deleteUser']);

    // Bans
    Route::post('/users/{id}/ban', [GeneralController::class, 'banUser']);
    Route::post('/users/{id}/unban', [GeneralController::class, 'unbanUser']);
    Route::get('/users/{id}/bans', [GeneralController::class, 'userBans']);

    // User Actions
    Route::post('/users/{id}/recalculate-stats', [GeneralController::class, 'recalculateUserStats']);
    Route::post('/users/{id}/render', [GeneralController::class, 'renderUser']);

    // Assets
    Route::get('/assets', [GeneralController::class, 'assets']);
    Route::get('/assets/{id}', [GeneralController::class, 'asset']);
    Route::post('/assets', [GeneralController::class, 'createAsset']);
    Route::post('/assets/{id}', [GeneralController::class, 'updateAsset']);
    Route::delete('/assets/{id}', [GeneralController::class, 'deleteAsset']);
    Route::post('/assets/{id}/grant', [GeneralController::class, 'grantAsset']);

    // Moderation
    Route::get('/pending-items', [GeneralController::class, 'pendingItems']);
    Route::post('/items/{id}/approve', [GeneralController::class, 'approveItem']);
    Route::post('/items/{id}/reject', [GeneralController::class, 'rejectItem']);

    // Logs
    Route::get('/logs', [GeneralController::class, 'logs']);
});

Route::get('/user/newest', [App\Http\Controllers\User\GeneralController::class, 'newestUsers']);
Route::get('/users', [App\Http\Controllers\User\GeneralController::class, 'browseUsers']);

Route::group(['middleware' => [RouteGuardOnlyAuthenticated::class]], function () {
    Route::group(['prefix' => 'user'], function () {
        Route::get('/me', [App\Http\Controllers\User\GeneralController::class, 'me']);
        Route::get('/ban-status', [App\Http\Controllers\User\GeneralController::class, 'banStatus']);
        Route::post('/unban', [App\Http\Controllers\User\GeneralController::class, 'unbanSelf']);

        Route::get('/leaderboard', [App\Http\Controllers\User\GeneralController::class, 'leaderboard']);
        Route::get('/wall/{id}', [App\Http\Controllers\User\GeneralController::class, 'wall']);
        Route::post('/wall/{id}/post', [App\Http\Controllers\User\GeneralController::class, 'postToWall']);

        Route::get('/inventory/me', [App\Http\Controllers\User\GeneralController::class, 'meInventory']);
        Route::post('/inventory/open-case/{id}', [App\Http\Controllers\User\GeneralController::class, 'openCase']);
        Route::get('/inventory/{id}', [App\Http\Controllers\User\GeneralController::class, 'inventory']);

        // Petitions
        Route::get('/petitions', [App\Http\Controllers\User\GeneralController::class, 'indexPetitions']);
        Route::post('/petitions', [App\Http\Controllers\User\GeneralController::class, 'createPetition']);
        Route::post('/petitions/{id}/vote', [App\Http\Controllers\User\GeneralController::class, 'votePetition']);
        Route::post('/petitions/{id}/approve', [App\Http\Controllers\User\GeneralController::class, 'approvePetition']);

        // Roadmap
        Route::get('/roadmap', [App\Http\Controllers\User\GeneralController::class, 'indexRoadmap']);
        Route::post('/roadmap', [App\Http\Controllers\User\GeneralController::class, 'createRoadmapItem']);
        Route::post('/roadmap/{id}', [App\Http\Controllers\User\GeneralController::class, 'updateRoadmapItem']);
        Route::delete('/roadmap/{id}', [App\Http\Controllers\User\GeneralController::class, 'deleteRoadmapItem']);

        // Avatar
        Route::get('/avatar/wearing', [App\Http\Controllers\User\GeneralController::class, 'getWearing']);
        Route::get('/avatar/colors', [App\Http\Controllers\User\GeneralController::class, 'getAvatarColors']);
        Route::post('/avatar/wear/{inventoryId}', [App\Http\Controllers\User\GeneralController::class, 'wearItem']);
        Route::post('/avatar/remove/{inventoryId}', [App\Http\Controllers\User\GeneralController::class, 'removeWornItem']);
        Route::post('/avatar/colors', [App\Http\Controllers\User\GeneralController::class, 'saveAvatarColors']);
        Route::post('/avatar/render', [App\Http\Controllers\User\GeneralController::class, 'renderAvatar'])->middleware("throttle:5,1");

        Route::group(['prefix' => 'settings'], function () {
            Route::post('/description', [SettingsController::class, 'updateDescription']);
            Route::post('/email', [SettingsController::class, 'changeEmail']);
            Route::post('/password', [SettingsController::class, 'changePassword']);
            Route::post('/username', [SettingsController::class, 'changeUsername']);
            Route::post('/privacy', [SettingsController::class, 'updatePrivacySettings']);
        });

        Route::post('/unfriend/{id}', [App\Http\Controllers\User\GeneralController::class, 'unfriend']);
        Route::group(['prefix' => 'friend'], function () {
            Route::get('/requests', [App\Http\Controllers\User\GeneralController::class, 'requests']);
            Route::post('/change/{id}/{state}', [App\Http\Controllers\User\GeneralController::class, 'changeRequestState']);
            Route::post('/{id}', [App\Http\Controllers\User\GeneralController::class, 'sendFriendRequest']);
        });

        Route::group(['prefix' => 'quests'], function () {
            Route::get('/', [QuestController::class, 'index']);
            Route::post('/claim/{id}', [QuestController::class, 'claim']);
        });

        Route::group(['prefix' => 'trades'], function () {
            Route::get('/{tab}', [TradeController::class, 'trades']);
            Route::post('/create/{toId}', [TradeController::class, 'create']);
            Route::post('/update/{id}/{state}', [TradeController::class, 'changeTradeState']);
        });

        Route::get('/{id}', [App\Http\Controllers\User\GeneralController::class, 'user']);

        // Payments
        Route::post('/payments/invoice', [App\Http\Controllers\User\GeneralController::class, 'createInvoice']);

    });
    Route::group(['prefix' => 'games'], function () {
        Route::get('/', [App\Http\Controllers\Game\GeneralController::class, 'index']);
        Route::post('/create', [App\Http\Controllers\Game\GeneralController::class, 'create']);
        Route::get('/{id}', [App\Http\Controllers\Game\GeneralController::class, 'show']);
        Route::get('/{id}/comments', [App\Http\Controllers\Game\GeneralController::class, 'comments']);
        Route::post('/{id}/comment', [App\Http\Controllers\Game\GeneralController::class, 'comment']);
        Route::post('/{id}/like', [App\Http\Controllers\Game\GeneralController::class, 'like']);
        Route::post('/{id}/dislike', [App\Http\Controllers\Game\GeneralController::class, 'dislike']);
    });
    Route::group(['prefix' => 'forum'], function () {
        Route::get('/categories', [App\Http\Controllers\Forum\GeneralController::class, 'categories']);

        Route::get('/threads/{categoryId}', [App\Http\Controllers\Forum\GeneralController::class, 'threads']);
        Route::post('/thread/{categoryId}', [App\Http\Controllers\Forum\GeneralController::class, 'createThread'])->middleware("throttle:3,1");
        Route::get('/thread/{id}', [App\Http\Controllers\Forum\GeneralController::class, 'thread']);

        Route::get('/replies/{threadId}', [App\Http\Controllers\Forum\GeneralController::class, 'replies']);
        Route::post('/reply/{threadId}', [App\Http\Controllers\Forum\GeneralController::class, 'createReply'])->middleware("throttle:5,1");

        // Admin/Moderator thread actions
        Route::post('/thread/{id}/scrub', [App\Http\Controllers\Forum\GeneralController::class, 'scrubThread'])->middleware([RouteGuardOnlyAdmin::class]);
        Route::post('/thread/{id}/delete', [App\Http\Controllers\Forum\GeneralController::class, 'deleteThread'])->middleware([RouteGuardOnlyAdmin::class]);
        Route::post('/thread/{id}/pin', [App\Http\Controllers\Forum\GeneralController::class, 'pinThread'])->middleware([RouteGuardOnlyAdmin::class]);
        Route::post('/thread/{id}/lock', [App\Http\Controllers\Forum\GeneralController::class, 'lockThread'])->middleware([RouteGuardOnlyAdmin::class]);

        // Admin/Moderator reply actions
        Route::post('/reply/{id}/scrub', [App\Http\Controllers\Forum\GeneralController::class, 'scrubReply'])->middleware([RouteGuardOnlyAdmin::class]);
        Route::post('/reply/{id}/delete', [App\Http\Controllers\Forum\GeneralController::class, 'deleteReply'])->middleware([RouteGuardOnlyAdmin::class]);
    });
    Route::group(['prefix' => 'marketplace'], function () {
        Route::get('/categories/{all?}', [App\Http\Controllers\Marketplace\GeneralController::class, 'categories']);

        Route::get('/items/{categories?}', [App\Http\Controllers\Marketplace\GeneralController::class, 'items']);
        Route::get('/item/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'item']);
        Route::post('/item/create', [App\Http\Controllers\Marketplace\GeneralController::class, 'create']);
        Route::post('/item/preview-render', [App\Http\Controllers\Marketplace\GeneralController::class, 'previewRender'])->middleware("throttle:6,1");
        Route::post('/item/update/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'updateItem']);

        Route::get('/comments/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'comments']);
        Route::post('/comments/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'comment'])->middleware("throttle:10,1");

        Route::get('/owns/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'owns']);
        Route::post('/buy/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'buy']);

        Route::get('/case-contents/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'caseContents']);
        Route::get('/owners/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'owners']);

        Route::get('/sell-requests/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'sellRequests']);
        Route::post('/sell-request/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'createSellRequest']);
        Route::delete('/sell-request/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'deleteSellRequest']);
        Route::post('/accept-sell-request/{id}', [App\Http\Controllers\Marketplace\GeneralController::class, 'acceptSellRequest']);
    });
});
