<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Photos are served by the app at /storage/…, not through the storage:link
 * symlink, which LWS's Apache refuses (every product photo answered 403 on
 * the web and in the mobile app).
 */
class PublicStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/public/catalogue/items');
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
        file_put_contents($this->dir.'/test-served.jpg', 'jpeg-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/test-served.jpg');
        parent::tearDown();
    }

    public function test_a_photo_is_served_without_a_session(): void
    {
        $response = $this->get('/storage/catalogue/items/test-served.jpg')->assertOk();

        $this->assertSame('jpeg-bytes', $response->streamedContent() ?: file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('max-age', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Set-Cookie'), 'a photo must not open a session');
    }

    public function test_nothing_outside_the_public_disk_is_reachable(): void
    {
        $this->get('/storage/../../.env')->assertNotFound();
        $this->get('/storage/..%2F..%2F.env')->assertNotFound();
        $this->get('/storage/catalogue/items/absent.jpg')->assertNotFound();
        $this->get('/storage/catalogue/items')->assertNotFound();
    }

    public function test_the_client_htaccess_sends_storage_to_the_app(): void
    {
        $lib = file_get_contents(base_path('deploy/lib.sh'));

        $this->assertStringContainsString('RewriteRule ^storage/ public/index.php [L]', $lib);
        $this->assertStringNotContainsString('|js|storage)/', $lib, 'storage must not be rewritten to the symlink');
        $this->assertStringContainsString('RewriteRule ^storage/ index.php [L]', file_get_contents(public_path('.htaccess')));
    }
}
