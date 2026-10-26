<?php

namespace Tests\Feature;

use App\Jobs\ManageClientJob;
use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Support\ClientMailSettings;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../../deploy/env-lib.php';

/**
 * Client spaces send through the master's mailbox.
 *
 * Provisioning wrote MAIL_MAILER=log into every client's .env, hard-coded:
 * password resets, invoices and messages from a client space were written to
 * a log file and never sent — so "Renvoyer les accès", which points a client
 * to "mot de passe oublié", led to an email that could not arrive either.
 */
class ClientMailSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function awkwardPasswords(): array
    {
        return [
            'plain' => ['simple123'],
            'symbols allowed bare' => ['p@ss:word/1+'],
            'a space' => ['two words'],
            'a hash, which starts a comment' => ['ab#cd'],
            'a dollar, which interpolates' => ['pa$word'],
            'a variable reference' => ['${APP_KEY}'],
            'a double quote' => ['say"hi'],
            'a single quote' => ["it's"],
            'both quotes and a dollar' => ["a'b\"c\$d"],
            // dotenv interpolates ${NAME} inside double quotes, and a value
            // with a single quote has to be double-quoted.
            'a single quote and a variable reference' => ["it's \${APP_NAME}"],
            'backslashes' => ['back\\slash\\'],
            'a regex back-reference' => ['x$1y\\1z'],
            'an equals sign' => ['=a=b='],
            'non-ASCII' => ['mötdépasse✓'],
            'a trailing space' => ['ends '],
        ];
    }

    #[DataProvider('awkwardPasswords')]
    public function test_a_password_survives_the_round_trip(string $password): void
    {
        // The symptom of getting this wrong is not an error — it is SMTP
        // rejecting the login, with a password that looks right in the file.
        $parsed = Dotenv::parse('MAIL_PASSWORD='.castlit_env_format($password));

        $this->assertSame($password, $parsed['MAIL_PASSWORD']);
    }

    #[DataProvider('awkwardPasswords')]
    public function test_a_password_survives_being_set_in_a_file(string $password): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "APP_NAME=Castl\nMAIL_PASSWORD=old\nDB_CONNECTION=sqlite\n");

        castlit_env_set($file, ['MAIL_PASSWORD' => $password]);

        $parsed = Dotenv::parse((string) file_get_contents($file));
        $this->assertSame($password, $parsed['MAIL_PASSWORD']);
        unlink($file);
    }

    public function test_setting_a_key_leaves_every_other_line_alone(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "APP_KEY=base64:keep\n# a comment\nMAIL_MAILER=log\nDB_DATABASE=/x.sqlite\n");

        castlit_env_set($file, ['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'mail62.lwspanel.com']);

        $this->assertSame(
            "APP_KEY=base64:keep\n# a comment\nMAIL_MAILER=smtp\nDB_DATABASE=/x.sqlite\nMAIL_HOST=mail62.lwspanel.com\n",
            file_get_contents($file),
        );
        unlink($file);
    }

    public function test_a_master_still_on_the_log_mailer_gives_its_clients_log(): void
    {
        // Nothing is sent that the master could not have sent.
        $this->assertSame(['MAIL_MAILER' => 'log'], castlit_client_mail_settings([]));
        $this->assertSame(['MAIL_MAILER' => 'log'], castlit_client_mail_settings(['CLIENT_MAIL_MAILER' => 'log']));
    }

    public function test_an_smtp_master_hands_its_settings_down(): void
    {
        $settings = castlit_client_mail_settings([
            'CLIENT_MAIL_MAILER' => 'smtp',
            'CLIENT_MAIL_SCHEME' => 'smtps',
            'CLIENT_MAIL_HOST' => 'mail62.lwspanel.com',
            'CLIENT_MAIL_PORT' => '465',
            'CLIENT_MAIL_USERNAME' => 'contact@castlitpos.com',
            'CLIENT_MAIL_PASSWORD' => 's3cret',
            'CLIENT_MAIL_FROM_ADDRESS' => 'contact@castlitpos.com',
            'CLIENT_MAIL_EXTRA' => 'ignored',
        ]);

        $this->assertSame([
            'MAIL_MAILER' => 'smtp',
            'MAIL_SCHEME' => 'smtps',
            'MAIL_HOST' => 'mail62.lwspanel.com',
            'MAIL_PORT' => '465',
            'MAIL_USERNAME' => 'contact@castlitpos.com',
            'MAIL_PASSWORD' => 's3cret',
            'MAIL_FROM_ADDRESS' => 'contact@castlitpos.com',
        ], $settings);
    }

    public function test_an_empty_setting_is_left_out_rather_than_written_empty(): void
    {
        // MAIL_SCHEME= (empty) is not the same as no scheme: the mailer reads
        // an empty string as a scheme it does not support.
        $settings = castlit_client_mail_settings([
            'CLIENT_MAIL_MAILER' => 'smtp',
            'CLIENT_MAIL_SCHEME' => '',
            'CLIENT_MAIL_HOST' => 'mail62.lwspanel.com',
        ]);

        $this->assertArrayNotHasKey('MAIL_SCHEME', $settings);
        $this->assertSame('mail62.lwspanel.com', $settings['MAIL_HOST']);
    }

    public function test_the_master_reads_its_own_smtp_settings(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => 'smtps',
            'mail.mailers.smtp.host' => 'mail62.lwspanel.com',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.username' => 'contact@castlitpos.com',
            'mail.mailers.smtp.password' => 's3cret',
            'mail.from.address' => 'contact@castlitpos.com',
        ]);

        $env = ClientMailSettings::forScripts();

        $this->assertSame('smtp', $env['CLIENT_MAIL_MAILER']);
        $this->assertSame('mail62.lwspanel.com', $env['CLIENT_MAIL_HOST']);
        $this->assertSame('465', $env['CLIENT_MAIL_PORT']);
        $this->assertSame('s3cret', $env['CLIENT_MAIL_PASSWORD']);
    }

    public function test_a_log_master_sends_log(): void
    {
        config(['mail.default' => 'log']);

        $this->assertSame(['CLIENT_MAIL_MAILER' => 'log'], ClientMailSettings::forScripts());
    }

    public function test_the_script_writes_a_client_env_from_the_environment(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "APP_NAME=Castl\nMAIL_MAILER=log\n");

        $process = new Process(['php', base_path('deploy/env-set.php'), $file], null, [
            'CLIENT_MAIL_MAILER' => 'smtp',
            'CLIENT_MAIL_HOST' => 'mail62.lwspanel.com',
            'CLIENT_MAIL_PORT' => '465',
            'CLIENT_MAIL_PASSWORD' => "a'b\"c\$d #e",
        ]);
        $process->mustRun();

        $parsed = Dotenv::parse((string) file_get_contents($file));
        $this->assertSame('smtp', $parsed['MAIL_MAILER']);
        $this->assertSame('mail62.lwspanel.com', $parsed['MAIL_HOST']);
        $this->assertSame("a'b\"c\$d #e", $parsed['MAIL_PASSWORD']);
        // The password reached the script through the environment — never as
        // an argument, which any user on a shared host can read with `ps`.
        $this->assertStringNotContainsString('a\'b', implode(' ', $process->getCommandLine() ? [$process->getCommandLine()] : []));
        unlink($file);
    }

    public function test_the_update_job_hands_the_mail_settings_to_manage_sh(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail62.lwspanel.com',
            'mail.mailers.smtp.password' => 's3cret',
        ]);

        // A stand-in manage.sh that records what it was given, and succeeds.
        $dir = sys_get_temp_dir().'/castlit-mail-'.uniqid();
        mkdir($dir);
        $seen = $dir.'/seen';
        file_put_contents($dir.'/manage.sh', "#!/bin/bash\necho \"\$CLIENT_MAIL_HOST|\$CLIENT_MAIL_PASSWORD|\$*\" > {$seen}\necho '{\"status\":\"success\",\"commit\":\"abc1234\"}'\n");
        config(['castlit.provision.manage_script' => $dir.'/manage.sh']);

        $subscription = Subscription::create([
            'business_name' => 'Boutique', 'contact_name' => 'C', 'email' => 'c@example.com',
            'desired_subdomain' => 'shop', 'currency' => 'MAD', 'status' => Subscription::STATUS_APPROVED,
        ]);
        $install = TenantInstall::create([
            'subscription_id' => $subscription->id, 'subdomain' => 'shop', 'domain' => 'shop.castlitpos.com',
            'status' => TenantInstall::STATUS_LIVE, 'is_enabled' => true,
        ]);

        ManageClientJob::dispatchSync($install->id, 'update');

        [$host, $password, $args] = explode('|', trim((string) file_get_contents($seen)));
        $this->assertSame('mail62.lwspanel.com', $host);
        $this->assertSame('s3cret', $password);
        $this->assertStringNotContainsString('s3cret', $args, 'the password must not be an argument');
    }

    public function test_provisioning_no_longer_hard_codes_the_log_mailer(): void
    {
        $script = (string) file_get_contents(base_path('deploy/provision.sh'));

        $this->assertStringNotContainsString('"MAIL_MAILER"=>"log"', $script);
        $this->assertStringContainsString('env-set.php', $script);
        $this->assertStringContainsString('env-set.php', (string) file_get_contents(base_path('deploy/manage.sh')));
    }
}
