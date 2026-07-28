<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testFileReturnsNullWhenNoFileUploaded(): void
    {
        $request = new Request('POST', '/api/groups/1/documents', [], [], [], []);

        self::assertNull($request->file('document'));
    }

    public function testFileReturnsUploadedFileData(): void
    {
        $files = [
            'document' => [
                'name' => 'fiche.pdf',
                'type' => 'application/pdf',
                'tmp_name' => '/tmp/phpXXXXXX',
                'error' => UPLOAD_ERR_OK,
                'size' => 12345,
            ],
        ];
        $request = new Request('POST', '/api/groups/1/documents', [], [], [], $files);

        $file = $request->file('document');

        self::assertNotNull($file);
        self::assertSame('fiche.pdf', $file['name']);
        self::assertSame('/tmp/phpXXXXXX', $file['tmp_name']);
        self::assertSame(UPLOAD_ERR_OK, $file['error']);
    }
}
