<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class UserService
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $sort
     * @return LengthAwarePaginator<int, User>
     */
    public function getPaginatedUsers(array $filters = [], int $perPage = 10, array $sort = []): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, User> */
        return $this->getFiltered($filters, $sort)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $sort
     * @return Builder<User>
     */
    public function getFiltered(array $filters, array $sort): Builder
    {
        $query = User::query()
            ->select([
                'id', 'first_name', 'last_name', 'email', 'phone', 'role',
                'avatar', 'dob', 'gender',
                'address_street', 'address_commune', 'address_province',
                'timezone', 'language', 'date_format', 'appearance_settings',
                'email_verified_at', 'phone_verified_at',
                'two_factor_confirmed_at', 'two_factor_type',
                'created_at',
                'status', 'last_login_at',
                'locked_at', 'locked_by', 'lock_reason',
                'deactivated_at', 'deactivated_by', 'deactivation_reason',
            ])
            ->with([
                'socialAccounts',
                'lockedBy:id,first_name,last_name',
                'deactivatedBy:id,first_name,last_name',
                'pendingInvitation',
            ]);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where(function ($q) use ($escaped) {
                $q->where('first_name', 'like', "%{$escaped}%")
                    ->orWhere('last_name', 'like', "%{$escaped}%")
                    ->orWhere('email', 'like', "%{$escaped}%")
                    ->orWhere('phone', 'like', "%{$escaped}%");
            });
        }

        match ($filters['show_deleted'] ?? 'exclude') {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => null,
        };

        if (! empty($filters['role'])) {
            $query->whereIn('role', (array) $filters['role']);
        }

        if (! empty($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (($filters['_2fa'] ?? null) === 'without') {
            $query->whereNull('two_factor_confirmed_at');
        }

        if (($filters['last_login_at'] ?? null) === 'inactive_30d') {
            $query->where(function ($q) {
                $q->whereNull('last_login_at')
                    ->orWhere('last_login_at', '<', now()->subDays(30));
            });
        }

        if (! empty($filters['created_at']) && is_array($filters['created_at']) && count($filters['created_at']) === 2) {
            $start = date('Y-m-d H:i:s', (int) $filters['created_at'][0] / 1000);
            $end = date('Y-m-d H:i:s', (int) $filters['created_at'][1] / 1000);
            $query->whereBetween('created_at', [$start, $end]);
        }

        $sortColumn = $sort['column'] ?? 'created_at';
        $sortDirection = $sort['direction'] ?? 'desc';

        if ($sortColumn === 'name') {
            $query->orderBy('first_name', $sortDirection)
                ->orderBy('last_name', $sortDirection);
        } else {
            $query->orderBy($sortColumn, $sortDirection);
        }

        return $query;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): User
    {
        $hashedPassword = Hash::make($data['password']);

        $user = new User;
        $user->fill([
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'password' => $hashedPassword,
            'dob' => $data['dob'] ?? null,
            'gender' => $data['gender'] ?? null,
            'address_street' => $data['address_street'] ?? null,
            'address_commune' => $data['address_commune'] ?? null,
            'address_province' => $data['address_province'] ?? null,
            'country_code' => $data['country_code'] ?? null,
        ]);
        $user->role = $data['role'];

        if (! empty($data['national_id'])) {
            $user->national_id = $data['national_id'];
        }

        $user->save();

        return $user;
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data): User
    {
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $role = $data['role'] ?? null;
        $nationalId = array_key_exists('national_id', $data) ? $data['national_id'] : '__skip__';
        unset($data['role'], $data['national_id']);

        $user->fill($data);

        if ($role !== null) {
            $user->role = $role;
        }

        if ($nationalId !== '__skip__') {
            $user->national_id = $nationalId;
        }

        $user->save();

        return $user->fresh() ?? $user;
    }

    public function delete(User $user): bool
    {
        return (bool) $user->delete();
    }

    /** @param array<int, int> $ids */
    public function bulkDelete(array $ids): int
    {
        return User::whereIn('id', $ids)->delete();
    }

    /**
     * Export: returns a query builder for streaming CSV.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    public function exportQuery(array $filters): Builder
    {
        $query = User::query();

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('first_name', 'like', "%{$filters['search']}%")
                    ->orWhere('last_name', 'like', "%{$filters['search']}%")
                    ->orWhere('email', 'like', "%{$filters['search']}%");
            });
        }

        return $query->latest();
    }

    /** @return array<string, mixed>[] */
    public function exportColumns(): array
    {
        return [
            ['key' => 'id',         'label' => __('api.export.headers.id')],
            ['key' => 'first_name', 'label' => __('api.export.headers.name'),
                'formatter' => fn ($v, $row) => trim(($row->first_name ?? '').' '.($row->last_name ?? ''))],
            ['key' => 'email',      'label' => __('api.export.headers.email')],
            ['key' => 'phone',      'label' => __('api.export.headers.phone')],
            ['key' => 'role',       'label' => __('api.export.headers.role'),
                'formatter' => fn ($v) => ucfirst((string) $v)],
            ['key' => 'created_at', 'label' => __('api.export.headers.created_at'),
                'formatter' => fn ($v) => $v?->format('Y-m-d H:i:s')],
        ];
    }

    public function exportFileName(): string
    {
        return __('api.export.users_filename');
    }
}
