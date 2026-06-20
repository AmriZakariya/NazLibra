<?php

use App\Http\Controllers\Api\AdjustmentController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API — v1
|--------------------------------------------------------------------------
|
| Auth strategy: Sanctum bearer tokens.
|   - POST /api/v1/auth/login  → returns token
|   - Every other request requires: Authorization: Bearer <token>
|
| Offline-first design notes:
|   - POST /api/v1/pos/sales is idempotent via the idempotency_key field.
|     The mobile client generates a UUID before going offline and retries
|     safely — the server returns the already-created sale on duplicate.
|   - GET /api/v1/sync/* accept ?since=<ISO-UTC> for delta sync.
|     Client stores the returned sync_at and sends it on the next call.
|   - POST /api/v1/inventory/adjustments is idempotent via idempotency_key.
|
*/

Route::prefix('v1')->group(function (): void {

    // ── Public ──────────────────────────────────────────────────────────────
    Route::post('auth/login', [AuthController::class, 'login']);

    // ── Protected (Sanctum token required) ──────────────────────────────────
    Route::middleware(['auth:sanctum', \App\Http\Middleware\ResolveApiContext::class])->group(function (): void {

        // Auth
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me',      [AuthController::class, 'me']);

        // Locations
        Route::get('locations', [LocationController::class, 'index']);

        // Dashboard KPIs
        Route::get('dashboard', [DashboardController::class, 'index']);

        // ── Delta sync ────────────────────────────────────────────────────────
        Route::prefix('sync')->group(function (): void {
            Route::get('items',    [SyncController::class, 'items']);    // paginated catalog
            Route::get('meta',     [SyncController::class, 'meta']);     // categories, brands, units, taxes
            Route::get('stock',    [SyncController::class, 'stock']);    // stock levels
            Route::get('contacts', [SyncController::class, 'contacts']); // customers
            Route::get('sales',    [SyncController::class, 'sales']);    // sales history
            Route::get('settings', [SyncController::class, 'settings']); // tenant settings (tz, currency, flags)
            // Legacy alias kept for backward compatibility during rollout.
            Route::get('catalog',  [SyncController::class, 'catalog']);
        });

        // ── POS — sales & tickets ─────────────────────────────────────────────
        Route::prefix('pos')->group(function (): void {
            // Sales
            Route::get('sales',        [SaleController::class, 'index']);
            Route::post('sales',       [SaleController::class, 'store']);
            Route::get('sales/{sale}', [SaleController::class, 'show']);

            // Returns / refunds
            Route::post('sales/{sale}/returns', [ReturnController::class, 'store']);

            // Held tickets (saved carts)
            Route::get('tickets',            [TicketController::class, 'index']);
            Route::post('tickets',           [TicketController::class, 'store']);
            Route::delete('tickets/{ticket}', [TicketController::class, 'destroy']);
        });

        // ── Cash register ─────────────────────────────────────────────────────
        Route::prefix('cash-register')->group(function (): void {
            Route::get('',          [CashRegisterController::class, 'status']);
            Route::post('open',     [CashRegisterController::class, 'open']);
            Route::post('close',    [CashRegisterController::class, 'close']);
            Route::post('movements',[CashRegisterController::class, 'movement']);
        });

        // ── Contacts (customers) ──────────────────────────────────────────────
        Route::get('contacts',           [ContactController::class, 'index']);
        Route::post('contacts',          [ContactController::class, 'store']);
        Route::get('contacts/{contact}', [ContactController::class, 'show']);

        // ── Item lookup & CRUD ────────────────────────────────────────────────
        Route::get('items/search',  [ItemController::class, 'search']);
        Route::post('items',         [ItemController::class, 'store']);
        Route::get('items/{item}',  [ItemController::class, 'show']);
        Route::put('items/{item}',  [ItemController::class, 'update']);

        // ── Inventory adjustments ─────────────────────────────────────────────
        Route::post('inventory/adjustments', [AdjustmentController::class, 'store']);
        Route::get('inventory/summary',      [InventoryController::class, 'summary']);
        Route::get('inventory/movements',    [InventoryController::class, 'movements']);
    });
});
