<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Gateway;
use Minos\MyBB\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * The gateway's answers read into the three outcomes of the contract: only a `202` accepts,
 * a `2xx` other than it is retried like no answer (shared rule F5a), and so is a `408`.
 */
final class GatewayTest extends TestCase
{
    /**
     * @dataProvider answers
     */
    public function testAnAnswerHasOneOutcome(array $answer, string $outcome, string $code, ?int $retryS): void
    {
        $result = Gateway::classify($answer['status'], $answer['body']);
        self::assertSame([$outcome, $code, $retryS], [$result['outcome'], $result['kod'], $result['ponow_za_s']]);
    }

    /** @return array<string,array{0:array{status:int,body:string},1:string,2:string,3:?int}> */
    public function answers(): array
    {
        return [
            '202'                       => [RecordingTransport::accepted(), Gateway::ACCEPTED, '', null],
            '200 is no acceptance'      => [['status' => 200, 'body' => '<html>hosting</html>'], Gateway::RETRY, 'http_200', null],
            '200 even with a 202 body'  => [RecordingTransport::accepted(['mybb:1'], 200), Gateway::RETRY, 'http_200', null],
            '201 with a 202 body'       => [RecordingTransport::accepted(['mybb:1'], 201), Gateway::RETRY, 'http_201', null],
            '204 is no acceptance'      => [['status' => 204, 'body' => ''], Gateway::RETRY, 'http_204', null],
            '200 with a refusal body'   => [['status' => 200, 'body' => '{"blad":{"kod":"brak_klucza","ponow_za_s":5}}'], Gateway::RETRY, 'http_200', null],
            '408 is a network failure'  => [['status' => 408, 'body' => ''], Gateway::RETRY, 'http_408', null],
            '429 with ponow_za_s'       => [RecordingTransport::refused(429, 'limit_minutowy_klucza', 30), Gateway::RETRY, 'limit_minutowy_klucza', 30],
            '429 kolejka_pelna'         => [RecordingTransport::refused(429, 'kolejka_pelna', 60), Gateway::RETRY, 'kolejka_pelna', 60],
            '503 without ponow_za_s'    => [RecordingTransport::refused(503, 'kolejka_niedostepna'), Gateway::RETRY, 'kolejka_niedostepna', null],
            '502 from a proxy'          => [['status' => 502, 'body' => '<html>'], Gateway::RETRY, 'http_502', null],
            'no answer'                 => [['status' => 0, 'body' => ''], Gateway::RETRY, 'siec', null],
            '401 brak_klucza'           => [RecordingTransport::refused(401, 'brak_klucza'), Gateway::CONFIG_ERROR, 'brak_klucza', null],
            '403 brak_webhooka'         => [RecordingTransport::refused(403, 'brak_webhooka'), Gateway::CONFIG_ERROR, 'brak_webhooka', null],
            '403 profil_niedozwolony'   => [RecordingTransport::refused(403, 'profil_niedozwolony', null, 0), Gateway::CONFIG_ERROR, 'profil_niedozwolony', null],
            '404 nie_znaleziono'        => [RecordingTransport::refused(404, 'nie_znaleziono'), Gateway::CONFIG_ERROR, 'nie_znaleziono', null],
            '413 limit_dlugosci'        => [RecordingTransport::refused(413, 'limit_dlugosci', null, 0), Gateway::CONFIG_ERROR, 'limit_dlugosci', null],
            'a redirect'                => [['status' => 301, 'body' => ''], Gateway::CONFIG_ERROR, 'http_301', null],
            'a code that is no code'    => [['status' => 400, 'body' => '{"blad":{"kod":"<script>"}}'], Gateway::CONFIG_ERROR, 'http_400', null],
        ];
    }

    /**
     * A `202` accepts the items its `przyjete` names, and no other: what it names is read
     * (strings only), nothing more.
     */
    public function testA202NamesTheItemsItAccepts(): void
    {
        self::assertSame(['mybb:1', 'mybb:3.1'],
            Gateway::classify(202, '{"przyjete":["mybb:1",7,null,"mybb:3.1",["mybb:4"]]}')['przyjete']);
        self::assertSame([], Gateway::classify(202, '{"przyjete":"mybb:1"}')['przyjete'], 'not a list');
        self::assertSame([], Gateway::classify(202, '')['przyjete'], 'no body');
        self::assertSame([], Gateway::classify(200, '{"przyjete":["mybb:1"]}')['przyjete'], 'only a 202 accepts');
    }

    public function testTheRefusedItemIsNamed(): void
    {
        self::assertSame(3, Gateway::classify(413, RecordingTransport::refused(413, 'limit_dlugosci', null, 3)['body'])['element']);
    }

    public function testTheRequestGoesToTheRouteWithTheKeyAndATenSecondBudget(): void
    {
        $transport = new RecordingTransport();
        (new Gateway($transport))->submit('https://gateway.example', 'wgb2b_klucz', [['id' => 'mybb:1', 'tekst' => 't']]);
        $request = $transport->requests[0];
        self::assertSame('https://gateway.example/api/v1/b2b/oceny', $request['url']);
        self::assertContains('X-Gateway-Key: wgb2b_klucz', $request['headers']);
        self::assertContains('Content-Type: application/json; charset=utf-8', $request['headers']);
        self::assertSame(10, $request['timeout']);
        self::assertSame(['elementy' => [['id' => 'mybb:1', 'tekst' => 't']]], json_decode($request['body'], true));
    }
}
