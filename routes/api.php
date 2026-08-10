<?php

use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectRestoreController;
use App\Http\Controllers\Api\V1\ProjectStatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
 * The 'api' middleware group is applied to this whole file by withRouting(), so naming it
 * here would run SubstituteBindings and the throttle twice. Permissions are checked with
 * can: because gate abilities are the permission names; ProjectService checks the same
 * ability again, so a caller reaching the service another way is still refused.
 */
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('projects', [ProjectController::class, 'index'])->middleware('can:projects.view');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->middleware('can:projects.view');
    Route::post('projects', [ProjectController::class, 'store'])->middleware('can:projects.create');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->middleware('can:projects.edit-details');
    Route::patch('projects/{project}/status', ProjectStatusController::class)->middleware('can:projects.update-status');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->middleware('can:projects.delete');

    Route::post('projects/{project}/restore', ProjectRestoreController::class)
        ->withTrashed()
        ->middleware('can:projects.restore');
});
