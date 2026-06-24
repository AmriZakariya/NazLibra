<?php

use App\Http\Controllers\Api\AdjustmentController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SaleInvoiceController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\ContactTransactionController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\VirtualDeviceApiController;
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
    Route::get('public/info', [PublicController::class, 'info']);

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
            Route::get('items',                [SyncController::class, 'items']);               // paginated catalog
            Route::get('meta',                 [SyncController::class, 'meta']);                // categories, brands, units, taxes
            Route::get('stock',                [SyncController::class, 'stock']);               // stock levels
            Route::get('contacts',             [SyncController::class, 'contacts']);            // customers & suppliers
            Route::get('sales',                [SyncController::class, 'sales']);               // sales history
            Route::get('settings',             [SyncController::class, 'settings']);            // tenant settings
            Route::get('invoices',             [SyncController::class, 'invoices']);            // sale invoices
            Route::get('contact-transactions', [SyncController::class, 'contactTransactions']); // manual ledger entries
            // Legacy alias kept for backward compatibility during rollout.
            Route::get('catalog',              [SyncController::class, 'catalog']);
            Route::get('printers',             [SyncController::class, 'printers']);
        });

        // ── Contact transactions ───────────────────────────────────────────────
        Route::get('contacts/{contact}/transactions',  [ContactTransactionController::class, 'index']);
        Route::post('contacts/{contact}/transactions', [ContactTransactionController::class, 'store']);

        // ── POS — sales & tickets ─────────────────────────────────────────────
        Route::prefix('pos')->group(function (): void {
            // Sales
            Route::get('sales',        [SaleController::class, 'index']);
            Route::post('sales',       [SaleController::class, 'store'])->middleware('api.action:sales.create');
            Route::get('sales/{sale}', [SaleController::class, 'show']);

            // Returns / refunds
            Route::get('sales/{sale}/returns',  [ReturnController::class, 'index']);
            Route::post('sales/{sale}/returns', [ReturnController::class, 'store'])->middleware('api.action:sales.refund');

            // Held tickets (saved carts)
            Route::get('tickets',            [TicketController::class, 'index']);
            Route::post('tickets',           [TicketController::class, 'store'])->middleware('api.action:sales.create');
            Route::delete('tickets/{ticket}', [TicketController::class, 'destroy'])->middleware('api.action:sales.create');
        });

        // ── Cash register ─────────────────────────────────────────────────────
        Route::prefix('cash-register')->group(function (): void {
            Route::get('',          [CashRegisterController::class, 'status']);
            Route::post('open',     [CashRegisterController::class, 'open'])->middleware('api.action:sales.create');
            Route::post('close',    [CashRegisterController::class, 'close'])->middleware('api.action:sales.create');
            Route::post('movements',[CashRegisterController::class, 'movement'])->middleware('api.action:sales.create');
        });

        // ── Contacts (customers & suppliers) ─────────────────────────────────
        Route::get('contacts',              [ContactController::class, 'index']);
        Route::post('contacts',             [ContactController::class, 'store']);
        Route::get('contacts/{contact}',    [ContactController::class, 'show']);
        Route::put('contacts/{contact}',    [ContactController::class, 'update']);
        Route::delete('contacts/{contact}', [ContactController::class, 'destroy']);

        // ── Item lookup & CRUD ────────────────────────────────────────────────
        Route::get('items/search',  [ItemController::class, 'search']);
        Route::post('items',         [ItemController::class, 'store']);
        Route::get('items/{item}',  [ItemController::class, 'show']);
        Route::put('items/{item}',  [ItemController::class, 'update']);

        // ── Inventory adjustments ─────────────────────────────────────────────
        Route::post('inventory/adjustments', [AdjustmentController::class, 'store'])->middleware('api.action:stock.adjust');
        Route::get('inventory/summary',      [InventoryController::class, 'summary']);
        Route::get('inventory/movements',    [InventoryController::class, 'movements']);

        // ── Sale invoices ─────────────────────────────────────────────────────
        Route::get('invoices',                          [SaleInvoiceController::class, 'index']);
        Route::get('invoices/{invoice}',                [SaleInvoiceController::class, 'show']);
        Route::post('sales/{sale}/invoice',             [SaleInvoiceController::class, 'generate']);

        // ── Users & PIN ───────────────────────────────────────────────────────
        Route::get('users',                 [UserController::class, 'index']);
        Route::put('users/{user}',          [UserController::class, 'update']);
        Route::post('users/set-pin',        [UserController::class, 'setPin']);
        Route::delete('users/pin',          [UserController::class, 'removePin']);
        Route::post('auth/pin-verify',      [UserController::class, 'pinVerify']);

        // ── Virtual devices ───────────────────────────────────────────────────
        Route::get('virtual-devices', [VirtualDeviceApiController::class, 'index']);

        // ── Printers ──────────────────────────────────────────────────────────
        // Note: push-config and clear-config must be registered BEFORE the {id}
        // wildcard routes to avoid being swallowed by the parameterised routes.
        Route::post('printers/push-config',   [PrinterController::class, 'pushConfig']);
        Route::delete('printers/clear-config',[PrinterController::class, 'clearConfig']);
        Route::get('printers',                [PrinterController::class, 'index']);
        Route::post('printers',               [PrinterController::class, 'store']);
        Route::put('printers/{id}',           [PrinterController::class, 'update']);
        Route::delete('printers/{id}',        [PrinterController::class, 'destroy']);

        // ── Printer groups ────────────────────────────────────────────────────
        Route::get('printer-groups',             [PrinterController::class, 'indexGroups']);
        Route::post('printer-groups',            [PrinterController::class, 'storeGroup']);
        Route::put('printer-groups/{id}',        [PrinterController::class, 'updateGroup']);
        Route::delete('printer-groups/{id}',     [PrinterController::class, 'destroyGroup']);
    });
});
