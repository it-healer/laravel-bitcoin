<?php

namespace ItHealer\LaravelBitcoin\Light\Api;

/**
 * The provider failed (no answer, rate limit, server error): another provider may answer.
 * Any other error (bad request, unknown address) is the same everywhere and is not retried.
 */
class ProviderException extends \RuntimeException
{
}
