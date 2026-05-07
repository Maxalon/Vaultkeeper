<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\DeckAssemblyController;
use App\Http\Controllers\DeckController;
use App\Http\Controllers\DeckEntryController;
use App\Http\Controllers\DeckImportController;
use App\Http\Controllers\DeckLegalityController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\FriendRequestController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LocationGroupController;
use App\Http\Controllers\NotificationController;
<<<<<<< HEAD
=======
use App\Http\Controllers\PrivacySettingController;
>>>>>>> 60f935d ( A1 migrations: friendships, user_privacy_settings, notifications, matcher index)
use App\Http\Controllers\PricesStatusController;
use App\Http\Controllers\PrivacySettingController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ScryfallCardController;
use App\Http\Controllers\UserCollectionController;
use App\Http\Controllers\UserSearchController;
use App\Http\Controllers\WantedMatchController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1'); // 10 attempts/minute/IP — brute force protection

    // Public — same throttle as login since it's the obvious next abuse vector.
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1');

    // Password recovery — public, throttled to match login. The broker also
    // applies a per-user 60s throttle (config/auth.php) on top of this.
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:10,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:10,1');
});

// Public — fed to the unauthenticated login hero. Returns one random
// non-land card from the most recently expanded set, or null when the
// catalogue is empty.
Route::get('cards/featured', [CardController::class, 'featured']);

// Authenticated API. The 'throttle:120,1' floor across the group caps a single
// authenticated user (or an attacker with one valid JWT) at 120 req/minute,
// which is generous enough for real SPA usage but stops a logged-in client
// from saturating the 1-CPU API container or fanning out queue jobs. Heavy
// import endpoints get a tighter sub-throttle below.
Route::middleware(['auth:api', 'throttle:120,1'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout',  [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me',       [AuthController::class, 'me']);
        Route::post('onboarding/complete', [AuthController::class, 'completeOnboarding']);
    });

    // Read-only Scryfall reference DB. Defined before apiResource('cards', ...)
    // so the literal `scryfall-cards/search` segment never collides with a
    // parameter binding (the prefixes are different anyway, but explicit > implicit).
    Route::get('scryfall-cards/search', [ScryfallCardController::class, 'search']);
    Route::get('scryfall-cards/printings', [ScryfallCardController::class, 'printings']);
    Route::get('scryfall-cards/{scryfallCard}', [ScryfallCardController::class, 'show']);

    Route::apiResource('cards', CardController::class);

    Route::get   ('decks',                                 [DeckController::class, 'index']);
    Route::post  ('decks',                                 [DeckController::class, 'store']);
    // Import endpoints dispatch long-running queue jobs
    // (BulkImportUserDecksJob can run for 30 minutes and fans 50
    // paginated HTTP fetches at Archidekt; CSV/text imports parse
    // user-supplied bodies). Hold each to 5/minute so a single user
    // can't fill the queue.
    Route::post  ('decks/import',                          [DeckImportController::class, 'store'])
        ->middleware('throttle:5,1');
    Route::post  ('decks/import/csv',                      [DeckImportController::class, 'csv'])
        ->middleware('throttle:5,1');
    Route::post  ('decks/import/bulk',                     [DeckImportController::class, 'bulk'])
        ->middleware('throttle:5,1');
    Route::get   ('decks/import/bulk/{key}',               [DeckImportController::class, 'bulkStatus']);
    Route::get   ('decks/{deck}',                          [DeckController::class, 'show']);
    Route::put   ('decks/{deck}',                          [DeckController::class, 'update']);
    Route::delete('decks/{deck}',                          [DeckController::class, 'destroy']);
    Route::get   ('decks/{deck}/totals',                   [DeckController::class, 'totals']);

    // Assemble/unassemble each fan out to ~deck-size CE writes inside one
    // transaction. The 120/min global floor would let one user do ~12K CE
    // writes/min across decks; pull that down to a level that still
    // supports normal "build a deck, change my mind, rebuild" cadences.
    Route::post  ('decks/{deck}/assemble',                 [DeckAssemblyController::class, 'assemble'])
        ->middleware('throttle:30,1');
    Route::post  ('decks/{deck}/unassemble',               [DeckAssemblyController::class, 'unassemble'])
        ->middleware('throttle:30,1');

    Route::get   ('decks/{deck}/entries',                  [DeckEntryController::class, 'index']);
    Route::post  ('decks/{deck}/entries',                  [DeckEntryController::class, 'store']);
    Route::post  ('decks/{deck}/wanted',                   [DeckEntryController::class, 'growWanted']);
    Route::patch ('decks/{deck}/entries/{entry}',          [DeckEntryController::class, 'update']);
    Route::post  ('decks/{deck}/entries/{entry}/edit-physical', [DeckEntryController::class, 'editPhysical']);
    Route::delete('decks/{deck}/entries/{entry}',          [DeckEntryController::class, 'destroy']);

    Route::get ('decks/{deck}/illegalities',               [DeckLegalityController::class, 'index']);
    Route::post('decks/{deck}/illegalities/ignore',        [DeckLegalityController::class, 'ignore']);
    Route::post('decks/{deck}/illegalities/unignore',      [DeckLegalityController::class, 'unignore']);

    Route::get('locations',                [LocationController::class, 'index']);
    Route::post('locations',               [LocationController::class, 'store']);
    Route::put('locations/{location}',     [LocationController::class, 'update']);
    Route::delete('locations/{location}',  [LocationController::class, 'destroy']);

    Route::get('location-groups',               [LocationGroupController::class, 'index']);
    Route::post('location-groups',              [LocationGroupController::class, 'store']);
    // Single-item move — atomic per drag-and-drop in the sidebar. Renumbers
    // siblings only within the affected source/destination parents.
    Route::post('location-groups/move',         [LocationGroupController::class, 'move'])
        ->middleware('throttle:120,1');
    Route::put('location-groups/{group}',       [LocationGroupController::class, 'update']);
    Route::delete('location-groups/{group}',    [LocationGroupController::class, 'destroy']);

    Route::get ('review',         [ReviewController::class, 'index']);
    Route::get ('review/count',   [ReviewController::class, 'count']);
    // resolve drives one transaction with up to 500 row-locks; tighter
    // throttle so a single user can't lock up the CE table.
    Route::post('review/resolve', [ReviewController::class, 'resolve'])
        ->middleware('throttle:30,1');

    // Backward-compat aliases for one release. New code should hit /review.
    Route::get ('pending-relocations',         [ReviewController::class, 'index']);
    Route::get ('pending-relocations/count',   [ReviewController::class, 'count']);
    Route::post('pending-relocations/resolve', [ReviewController::class, 'resolve'])
        ->middleware('throttle:30,1');

    Route::get('collection',               [CollectionController::class, 'index']);
    Route::get('collection/copies',        [CollectionController::class, 'copiesForCard']);
    Route::get('collection/totals',        [CollectionController::class, 'totals']);
    Route::post('collection/batch-move',   [CollectionController::class, 'batchMove']);
    Route::get('collection/{entry}',       [CollectionController::class, 'show']);
    Route::patch('collection/{entry}',     [CollectionController::class, 'update']);
    Route::delete('collection/{entry}',    [CollectionController::class, 'destroy']);

    // Pricing metadata. The actual per-card prices ride on the existing
    // card payloads (CollectionController, ScryfallCardController); this
    // endpoint just exposes "when did the last sync run".
    Route::get('prices/status',            [PricesStatusController::class, 'show']);

    // CSV / text bulk import — same heavy-job reasoning as decks/import*.
    Route::post('import', [ImportController::class, 'store'])
        ->middleware('throttle:5,1');

    // -------------------------------------------------------------------------
    // Friends (A3)
    //
    // Friend requests are the in-flight pending phase; once accepted/declined
    // they graduate to the friends list or are terminal. The canonical DB row
    // stores (least(a,b), greatest(a,b)) so there is exactly one row per pair.
    //
    // Throttle note: all friend write endpoints inherit the 120/min global
    // floor, which is sufficient — the dedup check (409 on duplicate request)
    // already prevents spam bursts.
    // -------------------------------------------------------------------------

    // Friend requests — pending phase.
    Route::post  ('friends/requests',       [FriendRequestController::class, 'store']);
    Route::get   ('friends/requests',       [FriendRequestController::class, 'index']);
    Route::patch ('friends/requests/{id}',  [FriendRequestController::class, 'update']);
    Route::delete('friends/requests/{id}',  [FriendRequestController::class, 'destroy']);

    // Accepted friends list + unfriending.
    Route::get   ('friends',               [FriendController::class, 'index']);
    Route::delete('friends/{user}',        [FriendController::class, 'destroy']);

    // Username search for friend discovery.
    // 30/min — tighter than the global floor to limit enumeration of
    // discoverable users. Excludes `discoverable=false` users, self, and
    // users with an existing pending/accepted relationship.
    Route::get('users/search', [UserSearchController::class, 'search'])
        ->middleware('throttle:30,1');

    // -------------------------------------------------------------------------
    // Friend visibility — read-only views of a friend's data (A2, A3)
    //
    // Access: accepted friendship required + friend's visibility setting
    // must be 'friends'. Enforced by CollectionEntryPolicy / DeckPolicy.
    // -------------------------------------------------------------------------
    Route::get('users/{user}/collection', [UserCollectionController::class, 'collection']);
    Route::get('users/{user}/decks',      [UserCollectionController::class, 'decks']);

    // -------------------------------------------------------------------------
    // Privacy settings (A3)
    //
    // One row per user; created with defaults by a User::created observer (A2).
    // Enum values are 'friends'|'private' — 'public' does not exist (decision 2).
    // -------------------------------------------------------------------------
    Route::get  ('privacy-settings', [PrivacySettingController::class, 'show']);
    Route::patch('privacy-settings', [PrivacySettingController::class, 'update']);

    // -------------------------------------------------------------------------
    // Wanted-card matcher (A4)
    //
    // Single SQL: deck_entries WHERE wanted ⨝ accepted friends
    // ⨝ collection_entries of friends WHERE scryfall_card_id matches
    //   AND locations.role = 'user' (not a deck shadow location)
    //   AND friend's collection_visibility = 'friends'
    //
    // Requires composite index on collection_entries(scryfall_id, user_id,
    // location_id). Verified via EXPLAIN ANALYZE before merge (A4).
    // -------------------------------------------------------------------------
    Route::get('decks/{deck}/wanted-matches', [WantedMatchController::class, 'index']);

    // -------------------------------------------------------------------------
    // Notifications (A5)
    //
    // Centralized inbox for all event types (friend requests, bulk-import
    // completions, deck-review items, etc.). Actions are declarative JSON
    // payloads; the gateway endpoint re-checks staleness before executing.
    //
    // The `read-all` literal must be registered before `{id}/read` so the
    // router doesn't try to bind "read-all" as an integer `{id}`.
    // -------------------------------------------------------------------------
    Route::post('notifications/read-all',           [NotificationController::class, 'markAllRead']);
    Route::get ('notifications',                    [NotificationController::class, 'index']);
    Route::post('notifications/{id}/read',          [NotificationController::class, 'markRead']);
    Route::post('notifications/{id}/actions/{key}', [NotificationController::class, 'executeAction']);
});
