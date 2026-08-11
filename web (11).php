




<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\WalletController;
use App\Http\Controllers\User\AirtimeController;
use App\Http\Controllers\User\DataController;
use App\Http\Controllers\User\CableController;
use App\Http\Controllers\User\ElectricityController;
use App\Http\Controllers\User\ExamController;
use App\Http\Controllers\User\DataPinController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\TransactionController;
use App\Http\Controllers\User\ReferralController;
use App\Http\Controllers\User\NotificationController;
use App\Http\Controllers\User\SupportController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\ServiceManagementController;
use App\Http\Controllers\Admin\TransactionManagementController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\User\RechargeCardController;
use App\Services\VtuProviderFactory;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Home
|--------------------------------------------------------------------------
*/
// Route::get('/', function () {
//     if (auth()->check()) {
//         return redirect()->route('user.dashboard');
//     }
//     return redirect()->route('login');
// })->name('home');

use App\Http\Controllers\LandingController;

Route::get('/', [LandingController::class, 'index'])->name('home');


Route::get('/debug-tenant-check', function () {
    return response()->json([
        'currentTenant_bound' => app()->bound('currentTenant'),
        'currentTenant_id'    => app()->bound('currentTenant') ? app('currentTenant')->id : null,
        'user_10_raw'         => \App\Models\User::withoutGlobalScope('tenant')->find(10)?->only(['id','tenant_id','email','deleted_at']),
    ]);
});



Route::get('/test-husmodata-electricity-realmeter', function () {
    $apiKey = config('vtu.vtu.providers.husmodata.api_key');
    $response = \Illuminate\Support\Facades\Http::withToken($apiKey, 'Token')
        ->timeout(30)
        ->post('https://husmodataapi.com/api/billpayment/', [
            'disco_name'   => 3, // swap to whichever DISCO you tested with
            'amount'       => 500,
            'meter_number' => '0201002000044', // swap in the real meter number you tested with
            'MeterType'    => 'Prepaid',
        ]);

    return response()->json([
        'http_status'  => $response->status(),
        'raw_body'     => $response->body(),
        'json_decoded' => $response->json(),
    ])->withHeaders(['Cache-Control' => 'no-store']);
});


Route::get('/debug-tenant-check2', function () {
    return response()->json([
        'user_10_normal_scoped_query' => \App\Models\User::find(10)?->only(['id','tenant_id','email']),
    ]);
});



Route::get('/debug-tenant-check2', function () {
    return response()->json([
        'user_10_normal_scoped_query' => \App\Models\User::find(10)?->only(['id','tenant_id','email']),
    ]);
});



Route::get('/test-maskawasub-metertype', function () {
    $apiKey = config('vtu.vtu.providers.maskawasub.api_key');
    $response = \Illuminate\Support\Facades\Http::withToken($apiKey, 'Token')
        ->timeout(30)
        ->get('https://www.maskawasub.com/api/validatemeter?' . http_build_query([
            'meternumber' => '0201002000044',
            'disconame'   => 3, // Abuja per Maskawasub's own DISCO map
            'mtype'       => 'Prepaid',
        ]));

    return response()->json([
        'status' => $response->status(),
        'body'   => $response->json() ?? $response->body(),
    ])->withHeaders(['Cache-Control' => 'no-store']);
});





Route::get('/test-husmodata-electricity-metertype', function () {
    $apiKey = config('vtu.vtu.providers.husmodata.api_key');
    $candidates = ['PREPAID', 'POSTPAID', 'Prepaid', 'prepaid', '01', '02', 'prepaid_meter', 'postpaid_meter'];

    $results = [];
    foreach ($candidates as $value) {
        $response = \Illuminate\Support\Facades\Http::withToken($apiKey, 'Token')
            ->timeout(30)
            ->post('https://husmodataapi.com/api/billpayment/', [
                'disco_name'   => 3,
                'amount'       => 500,
                'meter_number' => '0201002000044',
                'MeterType'    => $value,
            ]);

        $results[$value] = [
            'status' => $response->status(),
            'body'   => $response->json() ?? $response->body(),
        ];
    }

    return response()->json($results)->withHeaders(['Cache-Control' => 'no-store']);
});





Route::get('/test-husmodata-electricity-fail2', function () {
    $apiKey = config('vtu.vtu.providers.husmodata.api_key');
    $response = \Illuminate\Support\Facades\Http::withToken($apiKey, 'Token')
        ->timeout(30)
        ->post('https://husmodataapi.com/api/billpayment/', [
            'disco_name'   => 3,
            'amount'       => 500,
            'meter_number' => '0201002000044',
            'MeterType'    => 'prepaid',
        ]);

    return response()->json([
        'status' => $response->status(),
        'body'   => $response->json() ?? $response->body(),
    ])->withHeaders(['Cache-Control' => 'no-store']);
});



Route::get('/test-husmodata-exam-fail2', function () {
    $apiKey = config('vtu.vtu.providers.husmodata.api_key');
    $response = \Illuminate\Support\Facades\Http::withToken($apiKey, 'Token')
        ->timeout(30)
        ->post('https://husmodataapi.com/api/epin/', [
            'exam_name' => 'WAEC',
            'quantity'  => -1, // deliberately invalid
        ]);

    return response()->json([
        'status' => $response->status(),
        'body'   => $response->json() ?? $response->body(),
    ])->withHeaders(['Cache-Control' => 'no-store']);
});







Route::get('/test-husmodata-electricity-fail', function () {
    $apiKey = config('vtu.vtu.providers.husmodata.api_key');
    $response = \Illuminate\Support\Facades\Http::withToken($apiKey, 'Token')
        ->timeout(30)
        ->post('https://husmodataapi.com/api/billpayment/', [
            'disco_name'   => 3, // Abuja Electric, per your confirmed DISCO list
            'amount'       => 500,
            'meter_number' => '0000000000', // deliberately invalid
            'MeterType'    => 1,
        ]);

    return response()->json([
        'status' => $response->status(),
        'body'   => $response->json() ?? $response->body(),
    ])->withHeaders(['Cache-Control' => 'no-store']);
});

Route::get('/test-husmodata-exam-fail', function () {
    $apiKey = config('vtu.vtu.providers.husmodata.api_key');
    $response = \Illuminate\Support\Facades\Http::withToken($apiKey, 'Token')
        ->timeout(30)
        ->post('https://husmodataapi.com/api/epin/', [
            'exam_name' => 'INVALIDEXAM', // deliberately invalid
            'quantity'  => 1,
        ]);

    return response()->json([
        'status' => $response->status(),
        'body'   => $response->json() ?? $response->body(),
    ])->withHeaders(['Cache-Control' => 'no-store']);
});








Route::get('/test-husmodata-balance', function () {
    try {
        // Force Husmodata via factory
        $provider = App\Services\VtuProviderFactory::resolveByName('husmodata');
        $balance = $provider->getBalance();
        
        return response()->json([
            'success' => true,
            'provider' => 'Husmodata',
            'balance' => $balance,
            'raw_response' => $balance
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
});

Route::get('/test-husmodata-opcache-reset', function () {
    if (function_exists('opcache_reset')) {
        opcache_reset();
        return response()->json(['success' => true, 'message' => 'OPcache reset.']);
    }
    return response()->json(['success' => false, 'message' => 'opcache_reset() not available.']);
});

Route::get('/test-husmodata-config', function () {
    return response()->json([
        'env_direct'      => env('HUSMODATA_API_KEY'),
        'config_value'    => config('vtu.vtu.providers.husmodata.api_key'), // ← added the second 'vtu.'
        'config_base_url' => config('vtu.vtu.providers.husmodata.base_url'),
    ]);
});




Route::get('/test-husmodata-full-array', function () {
    $all = config('vtu.providers');

    return response()->json([
        'provider_keys'       => is_array($all) ? array_keys($all) : 'NOT AN ARRAY: ' . gettype($all),
        'has_husmodata_key'   => is_array($all) && array_key_exists('husmodata', $all),
        'husmodata_raw_value' => is_array($all) ? ($all['husmodata'] ?? 'KEY MISSING') : null,
        'n3tdata_raw_value'   => is_array($all) ? ($all['n3tdata'] ?? 'KEY MISSING') : null,
    ])->withHeaders(['Cache-Control' => 'no-store']);
});






Route::get('/csrf-token', function () {
    return response()->json(['token' => csrf_token()]);
})->name('csrf.token');



/*
|--------------------------------------------------------------------------
| Guest Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['guest'])->group(function () {
    Route::get('/login',           [AuthController::class, 'showLogin'])->name('login');
    Route::get('/register',        [AuthController::class, 'showRegister'])->name('register');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::get('/verify-email', [AuthController::class, 'showVerifyEmail'])->name('verification.notice');
    Route::get('/contact-us', function () {
    $landing = \App\Models\LandingPage::forCurrentTenant();
    return view('contact.index', compact('landing'));
    })->name('contact.index');
    
    
});

Route::get('/test-email', function () {
    \Illuminate\Support\Facades\Mail::raw('Test email from VTU app', function ($msg) {
        $msg->to('danieluwana@gmail.com')->subject('Test');
    });
    return 'Email sent!';
})->middleware('auth');



/*
|--------------------------------------------------------------------------
| Auth POST Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/register',        [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login',           [AuthController::class, 'login'])->name('auth.login');
    Route::post('/forgot-password', [AuthController::class, 'sendPasswordReset'])->name('password.email');
    Route::post('/reset-password',  [AuthController::class, 'resetPassword'])->name('password.update');
    Route::post('/auth/resend-verification',
        [AuthController::class, 'resendVerification']
    )->name('auth.resend-verification');
});

/*
|--------------------------------------------------------------------------
| Email Verification
|--------------------------------------------------------------------------
*/
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware(['signed'])
    ->name('verification.verify');

Route::post('/email/resend', [AuthController::class, 'resendVerification'])
    ->middleware(['throttle:6,1'])
    ->name('verification.send');

/**
 * 2) OUTSIDE the auth middleware group (guest-accessible via email link):
 */
Route::get('/pin/reset/{token}',  [ProfileController::class, 'showPinResetForm'])->name('profile.pin.reset.form');
Route::post('/pin/reset',         [ProfileController::class, 'resetPin'])->name('profile.pin.reset');



/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::post('/phone/otp/send',   [AuthController::class, 'sendPhoneOtp'])->name('phone.otp.send');
    Route::post('/phone/otp/verify', [AuthController::class, 'verifyPhoneOtp'])->name('phone.otp.verify');

    /*
    |----------------------------------------------------------------------
    | Dashboard
    |----------------------------------------------------------------------
    */
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('user.dashboard');

    /*
    |----------------------------------------------------------------------
    | Wallet
    |----------------------------------------------------------------------
    */
    Route::prefix('wallet')->name('wallet.')->group(function () {

        // ── Fund — Paystack ──────────────────────────────────────────────
        Route::get('/fund',           [WalletController::class, 'showFund'])->name('fund');
        Route::post('/fund/initiate', [WalletController::class, 'initiateFund'])->name('fund.initiate');
        Route::get('/fund/verify',    [WalletController::class, 'verifyFund'])->name('fund.verify');

        // ── Fund — Monnify ───────────────────────────────────────────────
        Route::post('/monnify/refresh', [WalletController::class, 'refreshMonnifyAccount'])->name('monnify.refresh');

        // ── Transfer ─────────────────────────────────────────────────────
        Route::get('/transfer',          [WalletController::class, 'showTransfer'])->name('transfer');
        Route::get('/transfer/success',  [WalletController::class, 'transferSuccess'])->name('transfer.success');
        Route::post('/transfer/lookup',  [WalletController::class, 'lookupRecipient'])->name('lookup');
        Route::post('/transfer/pin',     [WalletController::class, 'verifyTransferPin'])->name('transfer.pin');
        Route::post('/transfer/process', [WalletController::class, 'processTransfer'])->name('transfer.process');
    });

    /*
    |----------------------------------------------------------------------
    | VTU Services
    |----------------------------------------------------------------------
    */
    Route::prefix('airtime')->name('airtime.')->group(function () {
        Route::get('/',  [AirtimeController::class, 'index'])->name('index');
        Route::post('/', [AirtimeController::class, 'purchase'])->name('purchase');
    });

    // ── Data ────────────────────────────────────────────────────────────────
    Route::prefix('data')->name('data.')->group(function () {
        Route::get('/',       [DataController::class, 'index'])->name('index');
        Route::get('/plans',  [DataController::class, 'plans'])->name('plans');   // AJAX — new
        Route::post('/',      [DataController::class, 'purchase'])->name('purchase');
    });
     
    // ── Cable ───────────────────────────────────────────────────────────────
    Route::prefix('cable')->name('cable.')->group(function () {
        Route::get('/',       [CableController::class, 'index'])->name('index');
        Route::get('/plans',  [CableController::class, 'plans'])->name('plans');  // AJAX — new
        Route::post('/verify',[CableController::class, 'verify'])->name('verify');
        Route::post('/',      [CableController::class, 'purchase'])->name('purchase');
    });

    Route::prefix('electricity')->name('electricity.')->group(function () {
        Route::get('/',        [ElectricityController::class, 'index'])->name('index');
        Route::post('/verify', [ElectricityController::class, 'verify'])->name('verify');
        Route::post('/',       [ElectricityController::class, 'purchase'])->name('purchase');
    });

    Route::prefix('exam')->name('exam.')->group(function () {
        Route::get('/',  [ExamController::class, 'index'])->name('index');
        Route::post('/verify-jamb', [ExamController::class, 'verifyJamb'])->name('verify-jamb');
        Route::post('/', [ExamController::class, 'purchase'])->name('purchase');
    });

    Route::prefix('datapin')->name('datapin.')->group(function () {
        Route::get('/',  [DataPinController::class, 'index'])->name('index');
        Route::post('/', [DataPinController::class, 'purchase'])->name('purchase');
    });


// ── SIM Hosting (AutoPlug) ────────────────────────────────────────────────────
    Route::prefix('sim-hosting')->name('sim-hosting.')->group(function () {
        Route::get('/',         [\App\Http\Controllers\User\SimHostingController::class, 'index'])->name('index');
        Route::post('/',        [\App\Http\Controllers\User\SimHostingController::class, 'store'])->name('store');
        Route::get('/plans',    [\App\Http\Controllers\User\SimHostingController::class, 'plans'])->name('plans');
        Route::post('/purchase',[\App\Http\Controllers\User\SimHostingController::class, 'purchase'])->name('purchase');
    });

// ── Recharge Card Printing ───────────────────────────────────────────────────
    Route::prefix('recharge')->name('recharge.')->group(function () {
        Route::get('/',  [\App\Http\Controllers\User\RechargeCardController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\User\RechargeCardController::class, 'purchase'])->name('purchase');
    });

// ── Airtime to Cash ───────────────────────────────────────────────────────────
    Route::prefix('airtime-cash')->name('airtime-cash.')->group(function () {
        Route::get('/',  [\App\Http\Controllers\User\AirtimeCashController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\User\AirtimeCashController::class, 'store'])->name('store');
    });
    
    
    Route::prefix('datapin')->name('datapin.')->group(function () {
        Route::get('/',       [DataPinController::class, 'index'])->name('index');
        Route::get('/plans',  [DataPinController::class, 'plans'])->name('plans');  // ← ADD
        Route::post('/',      [DataPinController::class, 'purchase'])->name('purchase');
    });


    Route::get('/services', fn() => view('services.index'))->name('services.index');

    /*
    |----------------------------------------------------------------------
    | Transactions (Item 5)
    |----------------------------------------------------------------------
    */
    Route::prefix('transactions')->name('transactions.')->group(function () {
        Route::get('/',             [TransactionController::class, 'index'])->name('index');
        Route::get('/{id}',         [TransactionController::class, 'show'])->name('show');
        Route::get('/{id}/receipt', [TransactionController::class, 'receipt'])->name('receipt');
    });

    /*
    |----------------------------------------------------------------------
    | Notifications
    |----------------------------------------------------------------------
    */
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/',           [NotificationController::class, 'index'])->name('index');
        Route::post('/{id}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::post('/read-all',  [NotificationController::class, 'markAllRead'])->name('read.all');
    });

    /*
    |----------------------------------------------------------------------
    | Profile (Item 8)
    |----------------------------------------------------------------------
    */
     Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/',                    [ProfileController::class, 'index'])->name('index');
        Route::post('/update',             [ProfileController::class, 'update'])->name('update');
        Route::post('/change-password',    [ProfileController::class, 'changePassword'])->name('password');
        Route::post('/set-pin',            [ProfileController::class, 'setPin'])->name('pin');
        Route::post('/notifications',      [ProfileController::class, 'updateNotifications'])->name('notifications');
        Route::delete('/avatar',           [ProfileController::class, 'deleteAvatar'])->name('avatar.delete');
        // PIN reset — send email (auth required)
        Route::post('/pin/reset/send',     [ProfileController::class, 'sendPinResetEmail'])->name('pin.reset.send');
    });

    /*
    |----------------------------------------------------------------------
    | Referrals
    |----------------------------------------------------------------------
    */
        Route::get('/referrals', [ReferralController::class, 'index'])->name('referrals.index');
    
         Route::prefix('kyc')->name('kyc.')->group(function () {
             Route::get('/',        [\App\Http\Controllers\User\KycController::class, 'index'])->name('index');
             Route::post('/submit', [\App\Http\Controllers\User\KycController::class, 'submit'])->name('submit');
         });


    // ── Broadcast announcements (user view) ───────────────────────────────────
    Route::get('/broadcast/{broadcastId}',
        [\App\Http\Controllers\User\BroadcastViewController::class, 'show']
    )->name('broadcast.show');
    
     // ── Upgrade (user) ────────────────────────────────────────────────────────
    Route::prefix('upgrade')->name('upgrade.')->group(function () {
            Route::get('/',        [\App\Http\Controllers\User\UpgradeController::class, 'index'])->name('index');
            Route::post('/request',[\App\Http\Controllers\User\UpgradeController::class, 'request'])->name('request');
        });

    /*
    |----------------------------------------------------------------------
    | Support
    |----------------------------------------------------------------------
    */
    Route::prefix('support')->name('support.')->group(function () {
        Route::get('/',                [SupportController::class, 'index'])->name('index');
        Route::post('/',               [SupportController::class, 'store'])->name('store');
        Route::get('/{ticket}',        [SupportController::class, 'show'])->name('show');
        Route::post('/{ticket}/reply', [SupportController::class, 'reply'])->name('reply');
    });
    
    // ── API Key Management (user) ─────────────────────────────────────────────
    Route::prefix('api-keys')->name('api-keys.')->group(function () {
        Route::get('/',           [\App\Http\Controllers\User\ApiKeyController::class, 'index'])->name('index');
        Route::post('/',          [\App\Http\Controllers\User\ApiKeyController::class, 'store'])->name('store');
        Route::delete('/{id}',    [\App\Http\Controllers\User\ApiKeyController::class, 'revoke'])->name('revoke');
    });
    
    // Route::get('/contact-us', function () {
    // $landing = \App\Models\LandingPage::forCurrentTenant();
    // return view('contact.index', compact('landing'));
    // })->name('contact.index');
    
    /*
    |----------------------------------------------------------------------
    | Admin Routes
    |----------------------------------------------------------------------
    */
    Route::middleware(['role:admin|super_admin'])
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {

        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/',                [UserManagementController::class, 'index'])->name('index');
            Route::get('/search',          [UserManagementController::class, 'search'])->name('search');
            Route::get('/create',          [UserManagementController::class, 'create'])->name('create');
            Route::post('/',               [UserManagementController::class, 'store'])->name('store');
            Route::get('/{user}',          [UserManagementController::class, 'show'])->name('show');
            Route::post('/{user}/credit',  [UserManagementController::class, 'creditWallet'])->name('credit');
            Route::post('/{user}/debit',   [UserManagementController::class, 'debitWallet'])->name('debit');
            Route::post('/{user}/suspend', [UserManagementController::class, 'suspend'])->name('suspend');
            Route::post('/{user}/activate',[UserManagementController::class, 'activate'])->name('activate');
            Route::post('/{user}/upgrade', [UserManagementController::class, 'upgradeRole'])->name('upgrade');
        });

        Route::prefix('services')->name('services.')->group(function () {
            Route::get('/',                     [ServiceManagementController::class, 'index'])->name('index');
            Route::post('/network/{id}/toggle', [ServiceManagementController::class, 'toggleNetwork'])->name('network.toggle');
            Route::post('/data-plan',           [ServiceManagementController::class, 'storePlan'])->name('plan.store');
            Route::put('/data-plan/{id}',       [ServiceManagementController::class, 'updatePlan'])->name('plan.update');
            Route::delete('/data-plan/{id}',    [ServiceManagementController::class, 'deletePlan'])->name('plan.delete');
            Route::post('/data-plan/{id}/update', [ServiceManagementController::class, 'updatePlan'])->name('plan.update.post');
            // Cable plans
            Route::post('/cable-plan',              [ServiceManagementController::class, 'storeCablePlan'])->name('cable-plan.store');
            Route::put('/cable-plan/{id}',           [ServiceManagementController::class, 'updateCablePlan'])->name('cable-plan.update');
            Route::post('/cable-plan/{id}/update',   [ServiceManagementController::class, 'updateCablePlan'])->name('cable-plan.update.post');
            Route::delete('/cable-plan/{id}',        [ServiceManagementController::class, 'deleteCablePlan'])->name('cable-plan.delete');
            
            Route::post('/recharge-card-plan',            [ServiceManagementController::class, 'storeRechargeCardPlan'])->name('recharge-card-plan.store');
            Route::post('/recharge-card-plan/{id}/update', [ServiceManagementController::class, 'updateRechargeCardPlan'])->name('recharge-card-plan.update');
            Route::delete('/recharge-card-plan/{id}',      [ServiceManagementController::class, 'deleteRechargeCardPlan'])->name('recharge-card-plan.delete');
            
            
            // Electricity toggle
            Route::post('/electricity/{id}/toggle', [ServiceManagementController::class, 'toggleElectricity'])->name('electricity.toggle');
            // Exam toggle + price update
            Route::post('/exam/{id}/toggle', [ServiceManagementController::class, 'toggleExam'])->name('exam.toggle');
            Route::post('/exam/{id}/price',  [ServiceManagementController::class, 'updateExamPrice'])->name('exam.price');
        });

        Route::prefix('transactions')->name('transactions.')->group(function () {
            Route::get('/',                     [TransactionManagementController::class, 'index'])->name('index');
            Route::get('/{reference}',          [TransactionManagementController::class, 'show'])->name('show');
            Route::post('/{reference}/reverse', [TransactionManagementController::class, 'reverse'])->name('reverse');
        });

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/sales',         [ReportController::class, 'sales'])->name('sales');
            Route::get('/profit',        [ReportController::class, 'profit'])->name('profit');
            Route::get('/users',         [ReportController::class, 'users'])->name('users');
            Route::get('/export/{type}', [ReportController::class, 'export'])->name('export');
        });

        Route::prefix('broadcast')->name('broadcast.')->group(function () {
            Route::get('/',          [BroadcastController::class, 'index'])->name('index');
            Route::get('/{id}',      [BroadcastController::class, 'show'])->name('show');
            Route::post('/preview',  [BroadcastController::class, 'preview'])->name('preview');
            Route::post('/send',     [BroadcastController::class, 'send'])->name('send');
        });

      Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/',                 [SettingsController::class, 'index'])->name('index');
            Route::get('/providers',        [SettingsController::class, 'providers'])->name('providers');
            Route::post('/general',         [SettingsController::class, 'updateGeneral'])->name('general');
            Route::post('/payment',         [SettingsController::class, 'updatePayment'])->name('payment');
            Route::post('/vtu',             [SettingsController::class, 'updateVtu'])->name('vtu');
            Route::post('/sms',             [SettingsController::class, 'updateSms'])->name('sms');
            Route::post('/referral',        [SettingsController::class, 'updateReferral'])->name('referral');
            Route::post('/appearance',      [SettingsController::class, 'updateAppearance'])->name('appearance');
            Route::post('/providers',       [SettingsController::class, 'updateProviders'])->name('providers.save');
        
            Route::get('/credentials',      [SettingsController::class, 'credentials'])->name('credentials');
            Route::post('/credentials',     [SettingsController::class, 'updateCredentials'])->name('credentials.save');
});
        
        // ── Resellers (admin) ─────────────────────────────────────────────────────
        // Add inside the admin middleware group:
       Route::prefix('resellers')->name('resellers.')->group(function () {
    Route::get('/',                    [\App\Http\Controllers\Admin\ResellerController::class, 'index'])->name('index');
    Route::post('/{userId}/approve',   [\App\Http\Controllers\Admin\ResellerController::class, 'approve'])->name('approve');
    Route::post('/{userId}/reject',    [\App\Http\Controllers\Admin\ResellerController::class, 'reject'])->name('reject');
    Route::post('/{userId}/downgrade', [\App\Http\Controllers\Admin\ResellerController::class, 'downgrade'])->name('downgrade');
    Route::post('/settings/fees',      [\App\Http\Controllers\Admin\ResellerController::class, 'updateFees'])->name('fees');
});
        
        // ── SIM Hosting (AutoPlug) — admin ──────────────────────────────────────
        Route::prefix('sim-hosting')->name('sim-hosting.')->group(function () {
            Route::get('/',                    [\App\Http\Controllers\Admin\SimHostingController::class, 'index'])->name('index');
            Route::post('/plan/{id}',          [\App\Http\Controllers\Admin\SimHostingController::class, 'updatePlan'])->name('plan.update');
            Route::post('/{id}/status',        [\App\Http\Controllers\Admin\SimHostingController::class, 'updateStatus'])->name('status');
        });

        // ── Airtime to Cash — admin ──────────────────────────────────────────────
        Route::prefix('airtime-cash')->name('airtime-cash.')->group(function () {
            Route::get('/',                       [\App\Http\Controllers\Admin\AirtimeCashController::class, 'index'])->name('index');
            Route::get('/{airtimeCash}',          [\App\Http\Controllers\Admin\AirtimeCashController::class, 'show'])->name('show');
            Route::post('/{airtimeCash}/reveal-pin', [\App\Http\Controllers\Admin\AirtimeCashController::class, 'revealPin'])->name('reveal-pin');
            Route::post('/{airtimeCash}/approve', [\App\Http\Controllers\Admin\AirtimeCashController::class, 'approve'])->name('approve');
            Route::post('/{airtimeCash}/reject',  [\App\Http\Controllers\Admin\AirtimeCashController::class, 'reject'])->name('reject');
            Route::post('/settings',              [\App\Http\Controllers\Admin\AirtimeCashController::class, 'updateSettings'])->name('settings');
        });

        // KYC ADMIN
        Route::prefix('kyc')->name('kyc.')->group(function () {
             Route::get('/',              [\App\Http\Controllers\Admin\KycController::class, 'index'])->name('index');
             Route::get('/{id}',          [\App\Http\Controllers\Admin\KycController::class, 'show'])->name('show');
             Route::post('/{id}/approve', [\App\Http\Controllers\Admin\KycController::class, 'approve'])->name('approve');
             Route::post('/{id}/reject',  [\App\Http\Controllers\Admin\KycController::class, 'reject'])->name('reject');
             Route::post('/{id}/reveal-bvn', [\App\Http\Controllers\Admin\KycController::class, 'revealBvn'])->name('reveal-bvn');
         });
         
         // ── Support (admin) ──────────────────────────────────────────────────────
        Route::prefix('support')->name('support.')->group(function () {
            Route::get('/',                    [\App\Http\Controllers\Admin\SupportController::class, 'index'])->name('index');
            
            Route::get('/{ticket}',            [\App\Http\Controllers\Admin\SupportController::class, 'show'])->name('show');
            Route::post('/{ticket}/reply',     [\App\Http\Controllers\Admin\SupportController::class, 'reply'])->name('reply');
            Route::post('/{ticket}/status',    [\App\Http\Controllers\Admin\SupportController::class, 'updateStatus'])->name('status');
        });
         
         // ── Landing Page (admin) ────────────────────────────────────────────────────
        Route::prefix('landing')->name('landing.')->group(function () {
            Route::get('/',                 [\App\Http\Controllers\Admin\LandingPageController::class, 'edit'])->name('edit');
            Route::post('/hero',            [\App\Http\Controllers\Admin\LandingPageController::class, 'updateHero'])->name('hero');
            Route::post('/hero/image',      [\App\Http\Controllers\Admin\LandingPageController::class, 'uploadHeroImage'])->name('hero.image');
            Route::delete('/hero/image',    [\App\Http\Controllers\Admin\LandingPageController::class, 'removeHeroImage'])->name('hero.image.remove');
            Route::post('/features',        [\App\Http\Controllers\Admin\LandingPageController::class, 'updateFeatures'])->name('features');
            Route::post('/upload-image', [\App\Http\Controllers\Admin\LandingPageController::class, 'uploadImage'])->name('upload-image'); //Newly Added DeepSeek
            Route::post('/about',           [\App\Http\Controllers\Admin\LandingPageController::class, 'updateAbout'])->name('about');
            Route::post('/services',        [\App\Http\Controllers\Admin\LandingPageController::class, 'updateServices'])->name('services');
            Route::post('/contact',         [\App\Http\Controllers\Admin\LandingPageController::class, 'updateContact'])->name('contact');
            Route::post('/logo',   [\App\Http\Controllers\Admin\LandingPageController::class, 'uploadLogo'])->name('logo');
            Route::delete('/logo', [\App\Http\Controllers\Admin\LandingPageController::class, 'removeLogo'])->name('logo.remove');
            Route::post('/stats',      [\App\Http\Controllers\Admin\LandingPageController::class, 'updateStats'])->name('stats');
            Route::post('/logo/size',  [\App\Http\Controllers\Admin\LandingPageController::class, 'updateLogoSize'])->name('logo.size');
            Route::post('section-image/{section}', [\App\Http\Controllers\Admin\LandingPageController::class, 'updateSectionImage'])->name('landing.section-image');
            Route::delete('section-image/{section}', [\App\Http\Controllers\Admin\LandingPageController::class, 'removeSectionImage'])->name('landing.section-image.remove');Route::post('section-image/{section}', [\App\Http\Controllers\Admin\LandingPageController::class, 'updateSectionImage'])->name('landing.section-image');
            Route::delete('section-image/{section}', [\App\Http\Controllers\Admin\LandingPageController::class, 'removeSectionImage'])->name('landing.section-image.remove');
            Route::post('network-logo/{network}', [\App\Http\Controllers\Admin\LandingPageController::class, 'updateNetworkLogo'])->name('network-logo');
            Route::delete('network-logo/{network}', [\App\Http\Controllers\Admin\LandingPageController::class, 'removeNetworkLogo'])->name('network-logo.remove');
            Route::post('/manual-funding-banks', [\App\Http\Controllers\Admin\LandingPageController::class, 'updateManualFundingBanks'])->name('manual-funding-banks');
            
        });

        // ── Homepage Promo Banners — admin ───────────────────────────────────────
        Route::prefix('promo-banners')->name('promo-banners.')->group(function () {
            Route::get('/',                     [\App\Http\Controllers\Admin\PromoBannerController::class, 'index'])->name('index');
            Route::post('/',                    [\App\Http\Controllers\Admin\PromoBannerController::class, 'store'])->name('store');
            Route::post('/{promoBanner}',        [\App\Http\Controllers\Admin\PromoBannerController::class, 'update'])->name('update');
            Route::delete('/{promoBanner}',      [\App\Http\Controllers\Admin\PromoBannerController::class, 'destroy'])->name('destroy');
            Route::post('/{promoBanner}/toggle', [\App\Http\Controllers\Admin\PromoBannerController::class, 'toggleActive'])->name('toggle');
            Route::post('/{promoBanner}/move',   [\App\Http\Controllers\Admin\PromoBannerController::class, 'move'])->name('move');
        });
    
        
    });

});

/*
|--------------------------------------------------------------------------
| Webhooks (no CSRF — signature verified instead)
|--------------------------------------------------------------------------
*/
Route::prefix('webhook')->name('webhook.')->group(function () {
    Route::post('/paystack', [\App\Http\Controllers\Webhook\PaystackWebhookController::class, 'handle'])
        ->name('paystack');
    Route::post('/monnify',  [\App\Http\Controllers\Webhook\MonnifyWebhookController::class, 'handle'])
        ->name('monnify');
    Route::match(['get', 'post'], '/clubkonnect', [\App\Http\Controllers\Webhook\ClubKonnectWebhookController::class, 'handle'])
        ->name('clubkonnect');
});