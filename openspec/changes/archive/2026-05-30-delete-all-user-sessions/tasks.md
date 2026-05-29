## 1. Controller

- [x] 1.1 Add `destroy` method to `App\Http\Controllers\API\V1\Users\SessionsController` with `#[OAT\Delete]` annotation returning `204 No Content`

## 2. Routing

- [x] 2.1 Register `DELETE /sessions` route inside the `/users` group in `routes/v1.php` pointing to `UserSessionsController@destroy`

## 3. OpenAPI

- [x] 3.1 Run `php artisan openapi:generate` and commit the updated `public/openapi.json`

## 4. Tests

- [x] 4.1 Write feature test: authenticated user with sessions receives 204 and sessions are soft-deleted
- [x] 4.2 Write feature test: authenticated user with no sessions receives 204 (idempotent)
- [x] 4.3 Write feature test: unauthenticated request receives 401
- [x] 4.4 Write feature test: other users' sessions are not affected
