<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * A gateway transport that records each request and answers from a script — no network.
 */
final class RecordingTransport
{
    /** @var array<int,array{url:string,headers:array<int,string>,body:string,timeout:int}> */
    public $requests = [];

    /** @var array<int,array{status:int,body:string}> Answers, in order; the last one repeats. */
    private $answers;

    /** @param array<int,array{status:int,body:string}> $answers */
    public function __construct(array $answers = [])
    {
        $this->answers = $answers !== [] ? $answers : [self::accepted()];
    }

    /** @return array{status:int,body:string} */
    public function __invoke(string $url, array $headers, string $body, int $timeoutS): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutS];
        return count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
    }

    /** @return array<string,mixed> The last request's body, decoded. */
    public function lastBody(): array
    {
        $last = end($this->requests);
        return $last === false ? [] : (array)json_decode($last['body'], true);
    }

    /** @return array{status:int,body:string} */
    public static function accepted(): array
    {
        return ['status' => 202, 'body' => '{"przyjete":[]}'];
    }

    /** @return array{status:int,body:string} */
    public static function refused(int $status, string $code, ?int $retryS = null, ?int $element = null): array
    {
        $error = ['kod' => $code, 'komunikat' => 'x'];
        if ($retryS !== null) {
            $error['ponow_za_s'] = $retryS;
        }
        if ($element !== null) {
            $error['element'] = $element;
        }
        return ['status' => $status, 'body' => (string)json_encode(['blad' => $error])];
    }
}
