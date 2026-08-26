# Mobile / Token Auth (optional)

The starter is **cookie-only for the web SPA**. Sanctum is installed and the `User` model uses `HasApiTokens`, so bearer-token auth for mobile / 3rd-party clients can be enabled without new infrastructure.

## When to use which

| Client | Auth | CSRF |
|---|---|---|
| Web SPA (React) | Sanctum **cookie** session | yes (XSRF) |
| Mobile / CLI / 3rd-party | Sanctum **bearer token** | no (stateless) |

Never use bearer tokens in a browser (XSS risk) — the web app always uses cookies.

## Enabling token issuance

The routes are protected with `auth:sanctum`, which already accepts **both** a stateful cookie and an `Authorization: Bearer <token>` header — no route changes needed.

To let clients obtain a token, add an issue endpoint (kept out by default):

```php
// routes/api.php (public group)
Route::post('/auth/token', function (Illuminate\Http\Request $request) {
    $request->validate(['email' => 'required|email', 'password' => 'required', 'device' => 'required']);

    $user = App\Models\User::where('email', $request->email)->first();
    if (! $user || ! Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
        abort(422, __('auth.failed'));
    }

    return ['token' => $user->createToken($request->device)->plainTextToken];
})->middleware('throttle:login');
```

Client then sends:

```
Authorization: Bearer <token>
Accept: application/json
```

## Notes

- Scope tokens via `createToken($name, $abilities)` and check with `$user->tokenCan('...')`.
- Revoke: `$user->tokens()->delete()` (all) or `$user->currentAccessToken()->delete()` (current).
- 2FA: gate token issuance behind the same 2FA check as cookie login if required.
