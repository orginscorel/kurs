<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    public const TYPE_STAFF = 'staff';
    public const TYPE_TEACHER = 'teacher';
    public const TYPE_STUDENT = 'student';
    public const TYPE_GUARDIAN = 'guardian';

    protected $fillable = [
        'branch_id', 'name', 'username', 'email', 'phone', 'user_type',
        'password', 'is_active', 'must_change_password', 'avatar_path',
    ];

    /** initial_password: sistemin ürettiği başlangıç şifresi (şifreli; öğrenci değiştirince silinir). */
    protected $hidden = ['password', 'remember_token', 'initial_password'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'initial_password' => 'encrypted',
            'password_changed_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function isStudent(): bool
    {
        return $this->user_type === self::TYPE_STUDENT;
    }

    public function isGuardian(): bool
    {
        return $this->user_type === self::TYPE_GUARDIAN;
    }

    /** Yalnız öğretmen portalına giren hesabın taşıyabileceği rol(ler). */
    public const TEACHER_PORTAL_ROLES = ['ogretmen'];

    /**
     * Öğrenci, veli ya da YALNIZ öğretmen portalı hesabı (yönetim ekranına giremez).
     */
    public function isPortalUser(): bool
    {
        return $this->isStudent() || $this->isGuardian() || $this->isTeacherPortalUser();
    }

    /**
     * Öğretmen hesabı ve 'ogretmen' dışında hiçbir rolü yok → yalnız öğretmen portalı.
     * Kurum öğretmene ek rol (rehber, müdür…) verirse hesap personel sayılır ve yönetim ekranını da kullanır.
     */
    public function isTeacherPortalUser(): bool
    {
        if ($this->user_type !== self::TYPE_TEACHER) {
            return false;
        }

        return $this->getRoleNames()->diff(self::TEACHER_PORTAL_ROLES)->isEmpty();
    }

    /** Oturum açınca kullanıcının düştüğü portal (yönetim kabuğu için null). */
    public function portalKind(): ?string
    {
        return match (true) {
            $this->isStudent() => 'student',
            $this->isGuardian() => 'guardian',
            $this->isTeacherPortalUser() => 'teacher',
            default => null,
        };
    }

    /**
     * Portal hesabı başlangıç (kurumun verdiği) şifresiyle kullanılıyorsa ya da şifresi sıfırlandıysa
     * kendi şifresini belirlemeden portalı kullanamaz. Şifreli sütun çözülmeden, ham değerle bakılır.
     */
    public function requiresPasswordChange(): bool
    {
        if (! $this->isPortalUser()) {
            return (bool) $this->must_change_password;
        }

        $initial = $this->getAttributes()['initial_password'] ?? null;

        return (bool) $this->must_change_password || $initial !== null;
    }

    /** Yönetim uçlarını kullanabilen hesap: personel ya da ek (yönetim) rolü olan öğretmen. */
    public function isStaff(): bool
    {
        return $this->user_type === self::TYPE_STAFF
            || ($this->user_type === self::TYPE_TEACHER && ! $this->isTeacherPortalUser());
    }
}
