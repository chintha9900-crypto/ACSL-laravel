<?php

use App\Http\Controllers\Membership\MembershipApplicationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::controller(MembershipApplicationController::class)->group(function () {
    Route::get('membership/apply', 'create')->name('membership.apply');
    Route::post('membership/apply', 'store')->middleware('throttle:6,1')->name('membership.apply.store');
    Route::get('membership/apply/submitted/{application}', 'show')
        ->middleware('signed')
        ->name('membership.apply.submitted');
});

require __DIR__.'/auth.php';
