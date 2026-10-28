<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * A gateway transport that records each request and answers from a script — no network.
 * By default it accepts as the gateway does: `202` with every item of the request in
 * `przyjete`.
 */
final class RecordingTransport
{
    /** An answer's body that stands for "`przyjete` names every item of the request". */
    private const EVERY_ITEM = "\0every-item";

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
        $answer = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
        return self::answerTo($body, $answer);
    }

    /**
     * An answer as it goes back for a request: an acceptance of every item named
     * ({@see accepted} with no ids), anything else as it is. For transports written as
     * closures.
     *
     * @param string                       $requestBody The request's JSON body.
     * @param array{status:int,body:string} $answer      The answer; the gateway's acceptance by default.
     * @return array{status:int,body:string}
     */
    public static function answerTo(string $requestBody, ?array $answer = null): array
    {
        $answer = $answer ?? self::accepted();
        if ($answer['body'] === self::EVERY_ITEM) {
            $request = json_decode($requestBody, true);
            $answer['body'] = (string)json_encode(['przyjete' => array_column((array)($request['elementy'] ?? []), 'id')]);
        }
        return $answer;
    }

    /** @return array<string,mixed> The last request's body, decoded. */
    public function lastBody(): array
    {
        $last = end($this->requests);
        return $last === false ? [] : (array)json_decode($last['body'], true);
    }

    /**
     * The gateway's acceptance.
     *
     * @param array<int,string>|null $ids The ids `przyjete` names; null for every item of the
     *     request, as the gateway answers.
     * @param int                    $status Another `2xx` with the same body, for answers that
     *     are not the gateway's.
     * @return array{status:int,body:string}
     */
    public static function accepted(?array $ids = null, int $status = 202): array
    {
        return ['status' => $status, 'body' => $ids === null ? self::EVERY_ITEM : (string)json_encode(['przyjete' => $ids])];
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
