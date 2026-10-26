<?php

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Mail\PasswordResetMail;
use App\Modules\Identity\Mail\TestMail;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Mail::fake();
    RateLimiter::clear('password-reset');
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->owner = makeMember($this->a, User::factory()->create(['email' => 'dona@loja-a.com', 'name' => 'Ana Lima']));
    $this->other = makeMember($this->b, User::factory()->create(['email' => 'joao@loja-b.com']));
});

const SENT = 'Se este e-mail tiver acesso ao painel, enviamos um link para criar uma senha nova. Confira também o spam.';

function forgot($tenant, string $email)
{
    return test()->postJson(tenantUrl($tenant, 'merchant/auth/forgot-password'), ['email' => $email]);
}

function resetLink(): array
{
    $sent = Mail::sent(PasswordResetMail::class)->last();
    parse_str((string) parse_url($sent->url, PHP_URL_QUERY), $q);

    return [$sent, $q];
}

it('membro do restaurante recebe o link do próprio host, em português', function () {
    forgot($this->a, 'DONA@loja-a.com ')->assertOk()->assertExactJson(['message' => SENT]);

    Mail::assertSent(PasswordResetMail::class, 1);
    [$mail, $q] = resetLink();
    expect($mail->hasTo('dona@loja-a.com'))->toBeTrue()
        ->and($mail->url)->toStartWith('http://'.hostOf($this->a).'/painel/redefinir-senha?token=')
        ->and($q['email'])->toBe('dona@loja-a.com')
        ->and($mail->name)->toBe('Ana')
        ->and($mail->envelope()->subject)->toBe('Redefinir sua senha · painel de '.$this->a->name);
    $html = $mail->render();
    expect($html)->toContain('Criar senha nova')->toContain('60 minutos')->not->toContain('Reset Password');
    // O banco guarda só o hash do token.
    expect(DB::table('password_reset_tokens')->value('token'))->not->toBe($q['token']);
});

it('e-mail desconhecido, de outro restaurante ou desativado: mesma resposta e nenhum envio', function () {
    forgot($this->a, 'ninguem@x.com')->assertOk()->assertExactJson(['message' => SENT]);
    forgot($this->a, 'joao@loja-b.com')->assertOk()->assertExactJson(['message' => SENT]);
    $this->owner->forceFill(['active' => false])->save();
    forgot($this->a, 'dona@loja-a.com')->assertOk();
    Mail::assertNothingSent();
});

it('redefine com o link, derruba as sessões e o link não vale de novo', function () {
    merchantLogin($this->a, $this->owner);
    forgot($this->a, 'dona@loja-a.com');
    [, $q] = resetLink();
    $url = tenantUrl($this->a, 'merchant/auth/reset-password');
    $body = ['email' => $q['email'], 'token' => $q['token'], 'password' => 'Nova@1', 'password_confirmation' => 'Nova@1'];

    $this->postJson($url, [...$body, 'password' => 'fraca@1', 'password_confirmation' => 'fraca@1'])
        ->assertStatus(422)->assertJsonPath('details.fields.password.0', 'A nova senha precisa ter ao menos uma letra maiúscula.');
    $this->postJson($url, $body)->assertOk()->assertJsonPath('message', 'Senha criada. Entre com a senha nova.');

    expect(Hash::check('Nova@1', $this->owner->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $this->owner->id)->count())->toBe(0);
    $this->postJson($url, $body)->assertStatus(422)->assertJsonPath('code', 'reset_link_invalid');
    merchantLogin($this->a, $this->owner, 'Nova@1');
});

it('link usado no host de outro restaurante é recusado; token errado também', function () {
    forgot($this->a, 'dona@loja-a.com');
    [, $q] = resetLink();
    $body = ['email' => $q['email'], 'token' => $q['token'], 'password' => 'Nova@1', 'password_confirmation' => 'Nova@1'];

    $this->postJson(tenantUrl($this->b, 'merchant/auth/reset-password'), $body)->assertStatus(422)->assertJsonPath('code', 'reset_link_invalid');
    $this->postJson(tenantUrl($this->a, 'merchant/auth/reset-password'), [...$body, 'token' => str_repeat('a', 64)])->assertStatus(422)->assertJsonPath('code', 'reset_link_invalid');
    expect(Hash::check('Nova@1', $this->owner->fresh()->password))->toBeFalse();
});

it('um e-mail por minuto e limite de pedidos por IP', function () {
    forgot($this->a, 'dona@loja-a.com');
    forgot($this->a, 'dona@loja-a.com');
    Mail::assertSent(PasswordResetMail::class, 1);
    foreach (range(1, 3) as $i) {
        forgot($this->a, "x{$i}@x.com")->assertOk();
    }
    forgot($this->a, 'x9@x.com')->assertStatus(429);
});

it('painel SerTec: só a equipe recebe; link aponta para o host admin', function () {
    $admin = User::factory()->create(['email' => 'equipe@sertec.com', 'platform_role' => PlatformRole::Superadmin]);
    $this->postJson(adminUrl('admin/auth/forgot-password'), ['email' => 'dona@loja-a.com'])->assertOk()->assertExactJson(['message' => SENT]);
    Mail::assertNothingSent();

    $this->postJson(adminUrl('admin/auth/forgot-password'), ['email' => 'equipe@sertec.com'])->assertOk();
    [$mail, $q] = resetLink();
    expect($mail->url)->toStartWith('http://'.config('tenancy.admin_host').'/redefinir-senha?token=')
        ->and($mail->envelope()->subject)->toBe('Redefinir sua senha · Painel SerTec');

    // Link da equipe não serve no painel de restaurante (não é membro).
    $body = ['email' => $q['email'], 'token' => $q['token'], 'password' => 'Nova@1', 'password_confirmation' => 'Nova@1'];
    $this->postJson(tenantUrl($this->a, 'merchant/auth/reset-password'), $body)->assertStatus(422);
    $this->postJson(adminUrl('admin/auth/reset-password'), $body)->assertOk();
    expect(Hash::check('Nova@1', $admin->fresh()->password))->toBeTrue();
});

it('e-mail de teste: só superadmin, só para si; falha do SMTP vira mensagem clara', function () {
    $super = User::factory()->create(['email' => 'super@sertec.com', 'name' => 'Mozart Silva', 'platform_role' => PlatformRole::Superadmin]);
    $support = User::factory()->create(['platform_role' => PlatformRole::Support]);

    withSession(adminLogin($support))->postJson(adminUrl('admin/system/mail/test'))->assertForbidden();
    $session = adminLogin($super);
    withSession($session)->getJson(adminUrl('admin/system/mail'))->assertOk()
        ->assertJsonPath('data.mailer', 'array')->assertJsonPath('data.configured', false)->assertJsonMissingPath('data.password');
    withSession($session)->postJson(adminUrl('admin/system/mail/test'), ['to' => 'outro@x.com'])->assertOk()
        ->assertJsonPath('message', 'E-mail de teste enviado para super@sertec.com. Confira a caixa de entrada e o spam.');
    Mail::assertSent(TestMail::class, fn (TestMail $m) => $m->hasTo('super@sertec.com') && ! $m->hasTo('outro@x.com'));
});
