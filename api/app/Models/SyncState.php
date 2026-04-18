<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tiny key/value store for global sync metadata.
 *
 * Used by BulkSyncService for things like `last_migration_check`.
 * Survives `php artisan cache:clear` (unlike Cache::*), so we never
 * accidentally re-process the entire Scryfall migration history.
 */
class SyncState extends Model
{
    protected $table = 'sync_state';
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['key', 'value'];
}
