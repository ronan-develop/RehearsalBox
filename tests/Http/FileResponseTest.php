<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\FileResponse;
use App\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileResponseTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'rb-file');
        file_put_contents($this->file, '%PDF-1.4 contenu');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    #[Test]
    public function testItCarriesTheTypeAndTheExactSizeWithoutLoadingTheFile(): void
    {
        $response = new FileResponse($this->file, 'application/pdf', ['X-Test' => '1']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->statusCode());
        self::assertSame('application/pdf', $response->headers()['Content-Type']);
        self::assertSame((string) strlen('%PDF-1.4 contenu'), $response->headers()['Content-Length']);
        self::assertSame('1', $response->headers()['X-Test']);
        self::assertSame($this->file, $response->path());
    }

    #[Test]
    public function testTheBodyCanStillBeInspectedByTestsAndTools(): void
    {
        self::assertSame('%PDF-1.4 contenu', (new FileResponse($this->file, 'application/pdf'))->body());
    }

    #[Test]
    public function testSendStreamsTheFileToTheOutput(): void
    {
        $response = new FileResponse($this->file, 'application/pdf');

        ob_start();
        @$response->send();
        $output = (string) ob_get_clean();

        self::assertSame('%PDF-1.4 contenu', $output);
    }

    #[Test]
    public function testTheDefaultHeadersOfTheKernelStillApplyAndTheFileIsKept(): void
    {
        $secured = (new FileResponse($this->file, 'application/pdf'))->withDefaultHeaders(['Cache-Control' => 'private, no-store', 'Content-Type' => 'text/html']);

        self::assertInstanceOf(FileResponse::class, $secured);
        self::assertSame('private, no-store', $secured->headers()['Cache-Control']);
        self::assertSame('application/pdf', $secured->headers()['Content-Type'], 'le type du fichier n\'est jamais écrasé');
        self::assertSame($this->file, $secured->path());
    }

    #[Test]
    public function testAMissingFileIsRefusedAtConstructionNotHalfSent(): void
    {
        $this->expectException(\RuntimeException::class);

        new FileResponse($this->file . '.absent', 'application/pdf');
    }
}
