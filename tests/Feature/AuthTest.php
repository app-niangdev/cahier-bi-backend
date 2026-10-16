<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsTenants;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoles();
        $this->fakeWaha();
    }

    public function test_phone_format_is_validated(): void
    {
        foreach (['331234567', '7712345', '791234567', '77123456789'] as $phone) {
            $this->postJson('/api/auth/login', ['phone' => $phone, 'password' => 'x'])
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'VALIDATION_ERROR');
        }
    }

    public function test_manager_logs_in_with_formatted_phone_and_password(): void
    {
        $this->manager($this->tenant(), ['phone_one' => '771234567']);

        foreach (['77 123 45 67', '+221771234567', '00221 77-123-45-67'] as $phone) {
            $this->postJson('/api/auth/login', ['phone' => $phone, 'password' => 'Secret2026'])
                ->assertOk()
                ->assertJsonPath('payload.step', 'authenticated')
                ->assertJsonStructure(['payload' => ['access_token', 'refresh_token', 'user' => ['tenant' => ['primary_color', 'secondary_color', 'accent_color']]]]);
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendText'));
    }

    public function test_wrong_password_is_generic_and_throttled(): void
    {
        $this->manager($this->tenant(), ['phone_one' => '771234567']);

        $this->postJson('/api/auth/login', ['phone' => '771234567', 'password' => 'faux'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'INVALID_CREDENTIALS');
        $this->postJson('/api/auth/login', ['phone' => '781234567', 'password' => 'faux'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'INVALID_CREDENTIALS');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', ['phone' => '771234567', 'password' => 'faux']);
        }

        $this->postJson('/api/auth/login', ['phone' => '771234567', 'password' => 'Secret2026'])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_first_login_requires_whatsapp_otp_then_new_password(): void
    {
        $manager = User::factory()->firstLogin()->create([
            'tenant_id' => $this->tenant()->id,
            'phone_one' => '761112233',
            'password' => 'Provisoire1',
        ]);

        $step = $this->postJson('/api/auth/login', ['phone' => '761112233', 'password' => 'Provisoire1'])
            ->assertOk()
            ->assertJsonPath('payload.step', 'otp_required')
            ->assertJsonPath('payload.purpose', 'first_login')
            ->assertJsonPath('payload.phone_masked', '76 *** ** 33')
            ->assertJsonMissingPath('payload.access_token');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sendText')
            && $request['chatId'] === '221761112233@c.us');

        $challenge = $step->json('payload.challenge_token');

        $this->postJson('/api/auth/otp/verify', ['challenge_token' => $challenge, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OTP_INVALID')
            ->assertJsonPath('payload.attempts_left', 4);

        $resetToken = $this->postJson('/api/auth/otp/verify', ['challenge_token' => $challenge, 'code' => $this->lastOtp()])
            ->assertOk()
            ->assertJsonPath('payload.step', 'set_password')
            ->json('payload.reset_token');

        $this->postJson('/api/auth/password/set', [
            'reset_token' => $resetToken,
            'password' => 'MonMotDePasse',
            'password_confirmation' => 'MonMotDePasse',
        ])->assertOk()->assertJsonPath('payload.step', 'authenticated');

        $manager->refresh();
        $this->assertFalse($manager->must_change_password);
        $this->assertNotNull($manager->phone_verified_at);

        // Jeton à usage unique
        $this->postJson('/api/auth/password/set', [
            'reset_token' => $resetToken,
            'password' => 'Autre12345',
            'password_confirmation' => 'Autre12345',
        ])->assertStatus(422)->assertJsonPath('error_code', 'RESET_TOKEN_INVALID');

        $this->postJson('/api/auth/login', ['phone' => '761112233', 'password' => 'MonMotDePasse'])
            ->assertOk()
            ->assertJsonPath('payload.step', 'authenticated');
    }

    public function test_otp_is_invalidated_after_too_many_attempts(): void
    {
        User::factory()->firstLogin()->create(['tenant_id' => $this->tenant()->id, 'phone_one' => '761112233', 'password' => 'Provisoire1']);
        $challenge = $this->postJson('/api/auth/login', ['phone' => '761112233', 'password' => 'Provisoire1'])->json('payload.challenge_token');
        $code = $this->lastOtp();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/otp/verify', ['challenge_token' => $challenge, 'code' => '000000']);
        }

        $this->postJson('/api/auth/otp/verify', ['challenge_token' => $challenge, 'code' => $code])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_TOO_MANY_ATTEMPTS');
    }

    public function test_otp_resend_respects_cooldown(): void
    {
        User::factory()->firstLogin()->create(['tenant_id' => $this->tenant()->id, 'phone_one' => '761112233', 'password' => 'Provisoire1']);
        $challenge = $this->postJson('/api/auth/login', ['phone' => '761112233', 'password' => 'Provisoire1'])->json('payload.challenge_token');

        $this->postJson('/api/auth/otp/resend', ['challenge_token' => $challenge])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_RESEND_TOO_SOON');

        $this->travel(61)->seconds();

        $this->postJson('/api/auth/otp/resend', ['challenge_token' => $challenge])
            ->assertOk()
            ->assertJsonPath('payload.resends_left', 2);
    }

    public function test_whatsapp_failure_is_reported(): void
    {
        // Remplace les réponses simulées de setUp()
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['waha.test/*' => Http::response('down', 500)]);
        User::factory()->firstLogin()->create(['tenant_id' => $this->tenant()->id, 'phone_one' => '761112233', 'password' => 'Provisoire1']);

        $this->postJson('/api/auth/login', ['phone' => '761112233', 'password' => 'Provisoire1'])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'OTP_SEND_FAILED');
    }

    public function test_forgot_password_by_whatsapp_otp(): void
    {
        $manager = $this->manager($this->tenant(), ['phone_one' => '701234567']);
        $oldToken = $this->tokenFor($manager);

        // Numéro inconnu : refusé avant tout envoi de code
        $this->postJson('/api/auth/forgot-password', ['phone' => '709999999'])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PHONE_NOT_FOUND')
            ->assertJsonPath('message', fn ($m) => str_contains($m, '70 999 99 99'));
        Http::assertNothingSent();

        $challenge = $this->postJson('/api/auth/forgot-password', ['phone' => '70 123 45 67'])
            ->assertOk()
            ->assertJsonPath('payload.purpose', 'password_reset')
            ->json('payload.challenge_token');

        $resetToken = $this->postJson('/api/auth/otp/verify', ['challenge_token' => $challenge, 'code' => $this->lastOtp()])
            ->json('payload.reset_token');

        $this->postJson('/api/auth/password/set', [
            'reset_token' => $resetToken,
            'password' => 'Nouveau2026',
            'password_confirmation' => 'Nouveau2026',
        ])->assertOk();

        // Toutes les sessions précédentes sont révoquées
        $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->getJson('/api/manager/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'TOKEN_INVALID');

        $this->postJson('/api/auth/login', ['phone' => '701234567', 'password' => 'Nouveau2026'])->assertOk();
    }

    public function test_expired_subscription_refuses_login_and_cuts_open_sessions(): void
    {
        $tenant = $this->tenant();
        $manager = $this->manager($tenant, ['phone_one' => '771234567']);
        $token = $this->tokenFor($manager);

        Subscription::where('tenant_id', $tenant->id)->update([
            'starts_at' => today()->subDays(40),
            'ends_at' => today()->subDay(),
        ]);

        $this->postJson('/api/auth/login', ['phone' => '771234567', 'password' => 'Secret2026'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED')
            ->assertJsonPath('payload.subscription.state', 'expired');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/manager/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_expired_subscription_is_checked_before_sending_first_login_otp(): void
    {
        $tenant = $this->tenant();
        User::factory()->firstLogin()->create(['tenant_id' => $tenant->id, 'phone_one' => '761112233', 'password' => 'Provisoire1']);
        Subscription::where('tenant_id', $tenant->id)->delete();

        $this->postJson('/api/auth/login', ['phone' => '761112233', 'password' => 'Provisoire1'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');

        Http::assertNothingSent();
    }

    public function test_admin_phone_change_revokes_sessions_and_requires_confirmation(): void
    {
        $admin = $this->admin();
        $manager = $this->manager($this->tenant(), ['phone_one' => '771234567']);
        $oldToken = $this->tokenFor($manager);

        $this->asUser($admin)
            ->putJson("/api/users/update/{$manager->id}", ['phone_one' => '78 765 43 21'])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->getJson('/api/manager/dashboard')
            ->assertStatus(401);

        $challenge = $this->postJson('/api/auth/login', ['phone' => '787654321', 'password' => 'Secret2026'])
            ->assertJsonPath('payload.step', 'otp_required')
            ->assertJsonPath('payload.purpose', 'phone_verification')
            ->json('payload.challenge_token');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sendText') && $request['chatId'] === '221787654321@c.us');

        $this->postJson('/api/auth/otp/verify', ['challenge_token' => $challenge, 'code' => $this->lastOtp()])
            ->assertOk()
            ->assertJsonPath('payload.step', 'authenticated');

        $this->assertNotNull($manager->fresh()->phone_verified_at);
    }

    public function test_manager_cannot_change_login_phone_himself(): void
    {
        $manager = $this->manager($this->tenant(), ['phone_one' => '771234567']);

        $this->asUser($manager)->putJson('/api/auth/me', ['phone_one' => '781111111', 'address' => 'Thiès'])->assertOk();

        $this->assertSame('771234567', $manager->fresh()->phone_one);
        $this->assertSame('Thiès', $manager->fresh()->address);
    }

    public function test_admin_reset_access_restarts_first_login(): void
    {
        $manager = $this->manager($this->tenant(), ['phone_one' => '771234567']);

        $this->asUser($this->admin())
            ->postJson("/api/users/reset-access/{$manager->id}", ['password' => 'Provis2026'])
            ->assertOk();

        $this->postJson('/api/auth/login', ['phone' => '771234567', 'password' => 'Provis2026'])
            ->assertJsonPath('payload.step', 'otp_required')
            ->assertJsonPath('payload.purpose', 'first_login');
    }

    public function test_refresh_issues_new_tokens(): void
    {
        $manager = $this->manager($this->tenant());
        $refresh = $this->postJson('/api/auth/login', ['phone' => $manager->phone_one, 'password' => 'Secret2026'])->json('payload.refresh_token');

        $this->withHeader('Authorization', 'Bearer ' . $refresh)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['payload' => ['access_token', 'refresh_token']]);

        // Un jeton de rafraîchissement ne donne pas accès aux API
        $this->withHeader('Authorization', 'Bearer ' . $refresh)
            ->getJson('/api/manager/dashboard')
            ->assertStatus(401);
    }

    public function test_admin_logs_in_with_email_and_manager_cannot_reach_admin_routes(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'Secret2026'])
            ->assertOk()
            ->assertJsonPath('payload.user.role', 'ADMIN');

        $this->asUser($this->manager($this->tenant()))
            ->getJson('/api/tenants/list')
            ->assertStatus(403);
    }

    public function test_admin_cannot_log_in_with_phone(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/auth/login', ['phone' => $admin->phone_one, 'password' => 'Secret2026'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'LOGIN_METHOD_NOT_ALLOWED');
    }

    public function test_owner_and_gerant_cannot_log_in_with_email(): void
    {
        $tenant = $this->tenant();
        $owner = $this->manager($tenant);
        $gerant = $this->gerant($this->boutique($tenant));

        foreach ([$owner, $gerant] as $user) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Secret2026'])
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'LOGIN_METHOD_NOT_ALLOWED');
        }
    }

    public function test_wrong_password_does_not_reveal_login_channel(): void
    {
        $owner = $this->manager($this->tenant());

        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'faux'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'INVALID_CREDENTIALS');
    }

    public function test_email_password_reset_is_reserved_to_admin(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $owner = $this->manager($this->tenant());

        $this->postJson('/api/auth/forgot-password', ['email' => $owner->email])->assertOk();

        \Illuminate\Support\Facades\Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
    }

    /** WAHA répond que le numéro n'a pas de compte WhatsApp. */
    private function fakeNumberWithoutWhatsapp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'waha.test/api/contacts/check-exists*' => Http::response(['numberExists' => false]),
            'waha.test/*' => Http::response(['error' => 'not on WhatsApp'], 500),
        ]);
    }

    public function test_first_login_on_a_number_without_whatsapp_explains_the_problem(): void
    {
        User::factory()->firstLogin()->create([
            'tenant_id' => $this->tenant()->id,
            'phone_one' => '761112233',
            'password' => 'Provisoire1',
        ]);
        $this->fakeNumberWithoutWhatsapp();

        $this->postJson('/api/auth/login', ['phone' => '761112233', 'password' => 'Provisoire1'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'WHATSAPP_NUMBER_UNKNOWN')
            ->assertJsonPath('message', fn ($m) => str_contains($m, '76 111 22 33'));

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/sendText'));
    }

    public function test_forgot_password_on_a_number_without_whatsapp_explains_the_problem(): void
    {
        $manager = $this->manager($this->tenant());
        $this->fakeNumberWithoutWhatsapp();

        $this->postJson('/api/auth/forgot-password', ['phone' => $manager->phone_one])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'WHATSAPP_NUMBER_UNKNOWN');

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/sendText'));
    }

    public function test_forgot_password_refuses_disabled_and_admin_accounts(): void
    {
        $tenant = $this->tenant();
        $gerant = $this->gerant($this->boutique($tenant), ['status' => false]);

        $this->postJson('/api/auth/forgot-password', ['phone' => $gerant->phone_one])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_DISABLED');

        $admin = $this->admin();
        if ($admin->phone_one) {
            $this->postJson('/api/auth/forgot-password', ['phone' => $admin->phone_one])
                ->assertNotFound()
                ->assertJsonPath('error_code', 'PHONE_NOT_FOUND');
        }

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/sendText'));
    }

    public function test_whatsapp_refusal_is_reported_as_unavailable(): void
    {
        $manager = $this->manager($this->tenant());
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'waha.test/api/contacts/check-exists*' => Http::response(['numberExists' => true]),
            'waha.test/*' => Http::response(['error' => 'session STOPPED'], 422),
        ]);

        $this->postJson('/api/auth/forgot-password', ['phone' => $manager->phone_one])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'OTP_SEND_FAILED');
    }
}
