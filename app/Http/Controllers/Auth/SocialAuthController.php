<?php


namespace App\Http\Controllers\Auth;


use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;


class SocialAuthController extends Controller
{
    public function redirect($provider)
    {
        // Only allow google for now — you can extend
        if (!in_array($provider, ['google'])) {
            abort(404);
        }


        return Socialite::driver($provider)->stateless()->redirect();
    }


    public function callback($provider)
    {
        try {
            logger()->info('Google callback reached');

            // Temporarily disable SSL verification for development
            $guzzleConfig = [
                'verify' => false,
                'curl' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => 0,
                ],
            ];
            $socialUser = Socialite::driver($provider)
                ->stateless()
                ->setHttpClient(new \GuzzleHttp\Client($guzzleConfig))
                ->user();

            logger()->info('Google user data', [
                'email' => $socialUser->getEmail(),
                'name' => $socialUser->getName(),
                'id' => $socialUser->getId(),
            ]);

            $user = User::updateOrCreate(
                ['email' => $socialUser->getEmail()],
                [
                    'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                    'google_id' => $socialUser->getId(),
                    'avatar' => $socialUser->getAvatar(),
                    'password' => bcrypt('google_oauth_' . $socialUser->getId()), // Generate password for Google users
                ]
            );

            logger()->info('User created/updated', ['user_id' => $user->id, 'email' => $user->email]);

            // create personal access token (Sanctum)
            $token = $user->createToken('authToken')->plainTextToken;


            // Redirect to React app with token in query string
            $reactUrl = config('app.react_url', 'http://localhost:3000');
            return redirect("{$reactUrl}/auth/callback?token={$token}");


        } catch (\Exception $e) {
            // Log and handle
            logger()->error('Social callback error', ['err' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect('/')->with('error', 'Login failed');
        }
    }
}