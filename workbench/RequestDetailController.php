<?php

namespace Watch\Laravel\Workbench;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

// E2e only: authenticate an already-loaded user, with no persistence or query.
final class RequestDetailController
{
    public function show()
    {
        $user = new User();
        $user->setRawAttributes([
            'id' => 424242,
            'name' => 'SYNTHETIC_PRIVATE_NAME',
            'email' => 'SYNTHETIC_PRIVATE_EMAIL@example.test',
            'password' => 'SYNTHETIC_PRIVATE_PASSWORD',
            'remember_token' => 'SYNTHETIC_REMEMBER_TOKEN',
        ]);
        Auth::guard()->setUser($user);
        Log::info('watch-e2e detail correlated log', ['token' => 'SYNTHETIC_DETAIL_TOKEN']);

        return response('watch-e2e-detail-ok', 200);
    }
}
