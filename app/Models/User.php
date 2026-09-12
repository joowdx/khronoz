<?php

namespace App\Models;

use App\Enums\Permission;
use App\Models\Scopes\NotPlatformScope;
use App\Tenancy\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['agency_id', 'employee_id', 'name', 'email', 'password', 'permissions', 'invited_at'])]
#[Hidden(['password', 'remember_token', 'pending_email', 'pending_email_token', 'pending_email_expires_at', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /**
     * @use HasFactory<UserFactory>
     */
    use HasApiTokens, HasFactory, HasUlids, Notifiable, TwoFactorAuthenticatable;

    use PasskeyAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'invited_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => AsEnumCollection::of(Permission::class),
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => Str::lower(trim($value)));
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScope(NotPlatformScope::class);
    }

    public function isPlatform(): bool
    {
        return $this->agency_id === app(Tenant::class)->platformId();
    }

    /**
     * @param  mixed  $query
     * @param  mixed  $value
     * @param  mixed  $field
     * @return mixed
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $tenant = app(Tenant::class);
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        return $tenant->check() ? $query->where('agency_id', $tenant->id()) : $query;
    }

    public function allows(Permission $permission): bool
    {
        return $this->permissions->contains(fn (Permission $held) => $held->grants($permission));
    }
}
