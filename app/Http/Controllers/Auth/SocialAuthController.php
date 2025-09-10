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
        // Allow both google and linkedin
        if (!in_array($provider, ['google', 'linkedin'])) {
            abort(404);
        }


        return Socialite::driver($provider)->stateless()->redirect();
    }


    public function callback($provider)
    {
        try {
            logger()->info("{$provider} callback reached");

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

            logger()->info("{$provider} user data", [
                'email' => $socialUser->getEmail(),
                'name' => $socialUser->getName(),
                'id' => $socialUser->getId(),
            ]);

            // Prepare user data based on provider
            $userData = [
                'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                'avatar' => $socialUser->getAvatar(),
                'password' => bcrypt($provider . '_oauth_' . $socialUser->getId()),
            ];

            // Add provider-specific ID
            if ($provider === 'google') {
                $userData['google_id'] = $socialUser->getId();
            } elseif ($provider === 'linkedin') {
                $userData['linkedin_id'] = $socialUser->getId();
            }

            $user = User::updateOrCreate(
                ['email' => $socialUser->getEmail()],
                $userData
            );

            logger()->info('User created/updated', ['user_id' => $user->id, 'email' => $user->email, 'provider' => $provider]);

            // create personal access token (Sanctum)
            $token = $user->createToken('authToken')->plainTextToken;

            // Redirect to React app with token in query string
            $reactUrl = config('app.react_url', 'http://localhost:3000');
            return redirect("{$reactUrl}/auth/callback?token={$token}");

        } catch (\Exception $e) {
            // Log and handle
            logger()->error('Social callback error', ['provider' => $provider, 'err' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect('/')->with('error', 'Login failed');
        }
    }
}