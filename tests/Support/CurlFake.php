<?php

declare(strict_types=1);

namespace App\Services\Curl;

// Unqualified curl_* calls in App\Services\Curl resolve to these before the global functions, so tests
// fake the transport while request construction and response parsing stay real. Once declared they live
// for the whole process, hence the __curlMockActive guard (see fakeCurl()/stopFakingCurl() in Pest.php).
function curl_init(): object
{
    return ($GLOBALS['__curlMockActive'] ?? false) ? new \stdClass : \curl_init();
}

function curl_setopt(object $ch, int $option, mixed $value): bool
{
    if (! ($GLOBALS['__curlMockActive'] ?? false)) {
        return \curl_setopt($ch, $option, $value);
    }

    $GLOBALS['__curlMockSetopts'][$option] = $value;

    return true;
}

function curl_exec(object $ch): string|false
{
    if (! ($GLOBALS['__curlMockActive'] ?? false)) {
        return \curl_exec($ch);
    }

    return $GLOBALS['__curlMockResponse'] ?? '{}';
}

function curl_errno(object $ch): int
{
    if (! ($GLOBALS['__curlMockActive'] ?? false)) {
        return \curl_errno($ch);
    }

    return $GLOBALS['__curlMockErrno'] ?? 0;
}

function curl_error(object $ch): string
{
    if (! ($GLOBALS['__curlMockActive'] ?? false)) {
        return \curl_error($ch);
    }

    return $GLOBALS['__curlMockError'] ?? '';
}

function curl_getinfo(object $ch, int $option): mixed
{
    if (! ($GLOBALS['__curlMockActive'] ?? false)) {
        return \curl_getinfo($ch, $option);
    }

    return $GLOBALS['__curlMockHttpCode'] ?? 200;
}

function curl_close(object $ch): void {}
