<?php

declare(strict_types=1);

namespace Tigger\Webhooks;

use Tigger\Exceptions\HttpException;
use Tigger\Http\Request;

/**
 * Checks that an incoming webhook really comes from the provider.
 */
interface Verifier
{
    /**
     * Verifies the request signature against its raw body.
     *
     * @param Request $request Incoming webhook request.
     *
     * @return void
     *
     * @throws HttpException 400 when the signature is missing, malformed, expired or wrong.
     */
    public function verify(Request $request): void;
}
