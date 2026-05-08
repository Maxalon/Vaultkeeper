<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'email',
        'password',
        'onboarding_completed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    // ---------------------------------------------------------------------------
    // Social relations (friends, privacy, notifications)
    // ---------------------------------------------------------------------------

    /**
     * All friendship rows where this user appears on either side.
     * Use Friendship::scopeAccepted(), ::scopePendingFor(), etc. for filtering.
     */
    public function friendships(): HasMany
    {
        return $this->hasMany(Friendship::class, 'user_a_id')
            ->orWhere('user_b_id', $this->id);
    }

    /**
     * Accepted friends as User models (both directions).
     * NOTE: this relation cannot be eager-loaded with the standard
     * `with('friends')` because it spans two FK columns. Use the
     * Friendship model's scopes and `otherUser()` helper instead.
     *
     * @return Collection<int, User>
     */
    public function acceptedFriends(): Collection
    {
        $friendships = Friendship::query()
            ->accepted()
            ->where(function ($q) {
                $q->where('user_a_id', $this->id)
                    ->orWhere('user_b_id', $this->id);
            })
            ->with(['userA', 'userB'])
            ->get();

        return $friendships->map(fn (Friendship $f) => $f->otherUser($this));
    }

    /**
     * The user's privacy configuration row. Created lazily on first access
     * by the PrivacySettingController (or eagerly by the User observer in A2).
     */
    public function privacySettings(): HasOne
    {
        return $this->hasOne(UserPrivacySetting::class);
    }

    /**
     * Retrieve (or create with defaults) this user's privacy settings.
     */
    public function getOrCreatePrivacySettings(): UserPrivacySetting
    {
        return UserPrivacySetting::firstOrCreate(
            ['user_id' => $this->id],
            [
                'collection_visibility' => 'friends',
                'decks_visibility'      => 'friends',
                'discoverable'          => true,
            ],
        );
    }

    /**
     * All notifications for this user (newest first by default).
     * Named `appNotifications` to avoid collision with Laravel's built-in
     * `notifications()` relation from the Notifiable trait.
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class)->latest();
    }
}
