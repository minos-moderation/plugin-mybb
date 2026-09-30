<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Plugin;

use Minos\MyBB\Gateway;
use Minos\MyBB\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * The gateway's answers read into the three outcomes of the contract.
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
