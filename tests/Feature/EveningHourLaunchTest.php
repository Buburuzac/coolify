<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.evening_hour.app_uuid', 'evening-hour-test');
    config()->set('services.evening_hour.url', 'https://books.example.test');
    config()->set('services.evening_hour.ticket_secret', str_repeat('s', 32));

    $this->team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create(['environment_id' => $environment->id]);
    config()->set('services.evening_hour.app_uuid', $this->application->uuid);
});

function decodeEveningTicket(string $location): array
{
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    [$body, $signature] = explode('.', $query['ticket']);
    expect(hash_hmac('sha256', $body, str_repeat('s', 32)))->toBe($signature);

    return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
}

it('issues a signed ticket for a portal member who can read the app', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, [
        'role' => 'member',
        'access' => json_encode([['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['read']]]),
    ]);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $response = $this->get(route('evening-hour.launch'));
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://books.example.test/auth/ticket?ticket=');
    $ticket = decodeEveningTicket($response->headers->get('Location'));
    expect($ticket['sub'])->toBe($member->id)
        ->and($ticket['aud'])->toBe('evening-hour')
        ->and($ticket['manage'])->toBeFalse()
        ->and($ticket['exp'])->toBeGreaterThan(time());
});

it('lets a portal administrator manage the shared library', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    $ticket = decodeEveningTicket($this->get(route('evening-hour.launch'))->headers->get('Location'));
    expect($ticket['manage'])->toBeTrue();
});

it('does not let a member with application write access manage the shared library', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, [
        'role' => 'member',
        'access' => json_encode([['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['read', 'write']]]),
    ]);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $ticket = decodeEveningTicket($this->get(route('evening-hour.launch'))->headers->get('Location'));
    expect($ticket['manage'])->toBeFalse();
});

it('does not issue a ticket to a member without access', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member', 'access' => json_encode([])]);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $response = $this->get(route('evening-hour.launch'));
    expect((string) $response->headers->get('Location'))->not->toContain('/auth/ticket?ticket=');
});

it('does not issue a ticket when another team is selected', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);
    $otherTeam = Team::factory()->create();
    $admin->teams()->attach($otherTeam, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $otherTeam]);

    $this->get(route('evening-hour.launch'))->assertNotFound();
});

it('requires a portal login and complete configuration', function () {
    $this->get(route('evening-hour.launch'))->assertRedirect(route('login'));

    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);
    config()->set('services.evening_hour.ticket_secret', '');

    $this->get(route('evening-hour.launch'))->assertServiceUnavailable();
});
