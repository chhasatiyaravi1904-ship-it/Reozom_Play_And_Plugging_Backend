<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Events\AgentAdded;
use App\Http\Controllers\Concerns\FiltersAndPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\AgentVerifiedNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use FiltersAndPaginates;

    private const SORTABLE = ['name', 'email', 'role', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->withCount('listings')
            ->with([
                'loginLogs' => fn ($q) => $q->latest()->limit(1),
                'currentAgentPackage.package',
            ]);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $this->applyActiveFilter($query, $request);
        $this->applyExactFilter($query, $request, 'role');
        $this->applySort($query, $request, self::SORTABLE, 'created_at');

        return $this->paginatedResponse($query, $request, UserResource::class);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => Hash::make($request->validated('password')),
            'role' => $request->validated('role'),
            'is_active' => $request->boolean('is_active', true),
            ...$this->splitLocation($request->validated('location')),
        ]);

        if ($user->role === UserRole::Agent) {
            // Admin-created agents start unverified with no other way to
            // receive the verification link, and login is gated on
            // hasVerifiedEmail() — without this they could never sign in.
            AgentAdded::dispatch($user);
        }

        return api_success(new UserResource($user->loadMissing('currentAgentPackage.package')), 'User created.', 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->loadCount('listings')->load([
            'loginLogs' => fn ($q) => $q->latest()->limit(1),
            'currentAgentPackage.package',
        ]);

        return api_success(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->safe()->except(['password', 'location', 'email_verified']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->validated('password'));
        }

        if ($request->has('location')) {
            $data = [...$data, ...$this->splitLocation($request->validated('location'))];
        }

        $user->update($data);

        if ($request->has('email_verified')) {
            if ($request->boolean('email_verified')) {
                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                    $user->notify($user->role === UserRole::Agent ? new AgentVerifiedNotification : new WelcomeNotification);
                }
            } else {
                $user->forceFill(['email_verified_at' => null])->save();
            }
        }

        return api_success(new UserResource($user->loadMissing('currentAgentPackage.package')), 'User updated.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()?->id === $user->id) {
            return api_error('You cannot delete your own account.', 422);
        }

        $user->delete();

        return api_success(null, 'User deleted.');
    }

    /**
     * The admin UI collects a single free-text "City, ST" location field,
     * but the users table stores city/state separately — split on the
     * first comma so existing address columns stay usable elsewhere.
     *
     * @return array{city: ?string, state: ?string}
     */
    private function splitLocation(?string $location): array
    {
        if (! $location) {
            return ['city' => null, 'state' => null];
        }

        [$city, $state] = array_pad(explode(',', $location, 2), 2, null);

        return [
            'city' => trim($city) ?: null,
            'state' => $state !== null ? (trim($state) ?: null) : null,
        ];
    }
}
