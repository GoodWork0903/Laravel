<?php

namespace Laravel\Cashier\Tests\Unit;

use Illuminate\Http\Request;
use Laravel\Cashier\Http\Middleware\VerifyRedirectUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VerifyRedirectUrlTest extends TestCase
{
    public function test_it_passes_when_hosts_match()
    {
        $request = Request::create('http://foo.com/stripe/payment', 'GET', ['redirect' => 'http://foo.com/bar']);
        $middleware = new VerifyRedirectUrl;

        $response = $middleware->handle($request, function () {
            return 'Hello World!';
        });

        $this->assertSame('Hello World!', $response);
    }

    public function test_it_fails_on_host_mismatch()
    {
        $request = Request::create('http://baz.com/stripe/payment', 'GET', ['redirect' => 'http://foo.com/bar']);
        $middleware = new VerifyRedirectUrl;

        $this->expectException(AccessDeniedHttpException::class);

        $middleware->handle($request, function () {
            //
        });
    }

    public function test_it_passes_for_relative_urls()
    {
        $request = Request::create('http://baz.com/stripe/payment', 'GET', ['redirect' => '/foo/bar']);
        $middleware = new VerifyRedirectUrl;

        $response = $middleware->handle($request, function () {
            return 'Hello World!';
        });

        $this->assertSame('Hello World!', $response);
    }

    public function test_it_is_skipped_when_no_redirect_is_present()
    {
        $request = Request::create('http://baz.com/stripe/payment', 'GET');
        $middleware = new VerifyRedirectUrl;

        $response = $middleware->handle($request, function () {
            return 'Hello World!';
        });

        $this->assertSame('Hello World!', $response);
    }
}
