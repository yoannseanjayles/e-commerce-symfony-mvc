<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SmokeTest extends WebTestCase
{
    public function testRegistrationRateLimiterIsWired(): void
    {
        static::createClient();
        self::assertTrue(static::getContainer()->has('limiter.registration'));
    }

    public function testCartAddRejectsGet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cart/add/1');

        self::assertContains($client->getResponse()->getStatusCode(), [405, 404]);
    }

    public function testVerifyUserRejectsUnsignedUrl(): void
    {
        $client = static::createClient();
        $client->request('GET', '/verif?id=1&expires=9999999999');

        self::assertTrue($client->getResponse()->isRedirect());
    }

    public function testSecurityHeadersPresent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $response = $client->getResponse();
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }
}
