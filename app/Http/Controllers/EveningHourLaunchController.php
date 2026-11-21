<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\RedirectResponse;

class EveningHourLaunchController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $uuid = config('services.evening_hour.app_uuid');
        $url = config('services.evening_hour.url');
        $secret = config('services.evening_hour.ticket_secret');

        abort_unless(filled($uuid) && filled($url) && strlen((string) $secret) >= 32, 503);

        $application = Application::query()
            ->where('uuid', $uuid)
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', currentTeam()->id))
            ->firstOrFail();

        $this->authorize('view', $application);

        $user = auth()->user();
        $payload = [
            'aud' => 'evening-hour',
            'sub' => $user->id,
            'name' => $user->name,
            'manage' => $user->isAdminOfTeam($application->team()->id),
            'exp' => time() + 60,
            'nonce' => bin2hex(random_bytes(24)),
        ];

        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $ticket = $encoded.'.'.hash_hmac('sha256', $encoded, $secret);

        return redirect()->away(rtrim($url, '/').'/auth/ticket?ticket='.rawurlencode($ticket));
    }
}
