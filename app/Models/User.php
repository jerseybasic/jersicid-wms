<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'email',
        'whatsapp',
        'notify_whatsapp',
        'password',
        'role',
        'workspace_default',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'notify_whatsapp' => 'boolean',
    ];

    /** URL foto profil, atau null jika belum ada (tampilkan inisial) */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->profile_photo_path ? route('settings.photo', ['user' => $this->id, 'v' => basename($this->profile_photo_path, '.jpg')]) : null;
    }

    /** Dua huruf awal nama, mis. "Rina Gudang" → "RG" */
    public function getInitialsAttribute(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $letters = count($words) > 1 ? mb_substr($words[0], 0, 1) . mb_substr(end($words), 0, 1) : mb_substr($words[0] ?? 'U', 0, 2);

        return mb_strtoupper($letters ?: 'U');
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}
