<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kayedspace\Erpnext\Client\ErpClient;
use Kayedspace\Erpnext\Connection;
use Kayedspace\Erpnext\Documents\File;
use Kayedspace\Erpnext\Exceptions\ErpException;
use Kayedspace\Erpnext\Facades\Erpnext;

/**
 * Pinned against Frappe's own `upload_file` handler: multipart POST to
 * /api/method/upload_file, the File document returned under `message` rather than
 * `data`, and `is_private` defaulting to 1.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();

    app()->instance(ErpClient::class, new ErpClient(
        fn (): Connection => Connection::fromArray([
            'base_url' => 'https://erp.test',
            'api_key' => 'k',
            'api_secret' => 's',
        ]),
    ));

    $this->uploaded = fn (array $overrides = []) => Http::response(['message' => array_merge([
        'name' => 'FILE-0001',
        'doctype' => 'File',
        'file_name' => 'scan.pdf',
        'file_url' => '/private/files/scan.pdf',
        'is_private' => 1,
        'file_size' => 2048,
    ], $overrides)]);

    $this->tmp = tempnam(sys_get_temp_dir(), 'erp').'.pdf';
    file_put_contents($this->tmp, 'PDF-BYTES');
});

afterEach(function (): void {
    @unlink($this->tmp);
});

/**
 * Pull one field out of a multipart body. Laravel puts a Content-Length header between
 * the disposition line and the value, so a naive regex on adjacent newlines misses it.
 */
function multipartField(string $body, string $name): ?string
{
    $pattern = '/name="'.preg_quote($name, '/').'".*?\r\n\r\n(.*?)\r\n--/s';

    return preg_match($pattern, $body, $m) === 1 ? $m[1] : null;
}

// -----------------------------------------------------------------------------
// Transport
// -----------------------------------------------------------------------------

it('posts multipart to the upload endpoint, not the resource api', function (): void {
    Http::fake(['*' => ($this->uploaded)()]);

    Erpnext::upload()->fromContents('BYTES', 'scan.pdf')->store();

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://erp.test/api/method/upload_file'
        && str_contains($r->header('Content-Type')[0] ?? '', 'multipart/form-data'));
});

it('reads the created File out of message, where frappe puts it', function (): void {
    Http::fake(['*' => ($this->uploaded)()]);

    $file = Erpnext::upload()->fromContents('BYTES', 'scan.pdf')->store();

    expect($file)->toBeInstanceOf(File::class)
        ->and($file->name())->toBe('FILE-0001')
        ->and($file->fileUrl())->toBe('/private/files/scan.pdf')
        ->and($file->fileSize())->toBe(2048)
        ->and($file->exists())->toBeTrue();
});

it('takes the filename from the path when none is given', function (): void {
    Http::fake(['*' => ($this->uploaded)()]);

    Erpnext::upload()->fromPath($this->tmp)->store();

    Http::assertSent(fn (Request $r): bool => str_contains($r->body(), basename($this->tmp)));
});

it('can override the stored name', function (): void {
    Http::fake(['*' => ($this->uploaded)()]);

    Erpnext::upload()->fromPath($this->tmp)->as('invoice-scan.pdf')->store();

    Http::assertSent(fn (Request $r): bool => str_contains($r->body(), 'invoice-scan.pdf'));
});

// -----------------------------------------------------------------------------
// Visibility
// -----------------------------------------------------------------------------

it('uploads privately by default, matching frappe', function (): void {
    Http::fake(['*' => ($this->uploaded)()]);

    Erpnext::upload()->fromContents('BYTES', 'scan.pdf')->store();

    Http::assertSent(fn (Request $r): bool => multipartField($r->body(), 'is_private') === '1');
});

it('uploads publicly only when asked', function (): void {
    Http::fake(['*' => ($this->uploaded)(['is_private' => 0, 'file_url' => '/files/scan.pdf'])]);

    $file = Erpnext::upload()->fromContents('BYTES', 'scan.pdf')->public()->store();

    expect($file->isPrivate())->toBeFalse()
        ->and($file->fileUrl())->toBe('/files/scan.pdf');

    Http::assertSent(fn (Request $r): bool => multipartField($r->body(), 'is_private') === '0');
});

// -----------------------------------------------------------------------------
// Attaching
// -----------------------------------------------------------------------------

it('sends the attachment target with the upload', function (): void {
    Http::fake(['*' => ($this->uploaded)()]);

    Erpnext::upload()
        ->fromContents('BYTES', 'scan.pdf')
        ->attachTo('Sales Invoice', 'ACC-SINV-0001')
        ->store();

    Http::assertSent(fn (Request $r): bool => multipartField($r->body(), 'doctype') === 'Sales Invoice'
        && multipartField($r->body(), 'docname') === 'ACC-SINV-0001');
});

/**
 * Frappe records the link on the File doc but does not write the value back onto the
 * parent — only the desk UI does that. Without this second call the attachment exists
 * and the Attach field still reads empty.
 */
it('writes the file url into the attach field, because frappe does not', function (): void {
    Http::fake([
        '*/api/method/upload_file' => ($this->uploaded)(),
        '*' => Http::response(['data' => ['name' => 'ACC-SINV-0001']]),
    ]);

    Erpnext::upload()
        ->fromContents('BYTES', 'scan.pdf')
        ->attachTo('Sales Invoice', 'ACC-SINV-0001', 'custom_scan')
        ->store();

    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
        && $r->url() === 'https://erp.test/api/resource/Sales%20Invoice/ACC-SINV-0001'
        && $r['custom_scan'] === '/private/files/scan.pdf');
});

it('leaves the field alone when told to', function (): void {
    Http::fake(['*' => ($this->uploaded)()]);

    Erpnext::upload()
        ->fromContents('BYTES', 'scan.pdf')
        ->attachTo('Sales Invoice', 'ACC-SINV-0001', 'custom_scan')
        ->withoutSettingField()
        ->store();

    Http::assertSentCount(1);
});

it('refuses a field name with nothing to attach it to', function (): void {
    Http::fake();

    $upload = Erpnext::upload()->fromContents('BYTES', 'scan.pdf');
    // attachTo() is the only way to set a field, so reach past it deliberately.
    $reflection = new ReflectionProperty($upload, 'fieldname');
    $reflection->setValue($upload, 'custom_scan');

    expect(fn () => $upload->store())
        ->toThrow(ErpException::class, 'meaningless without a doctype');

    Http::assertNothingSent();
});

// -----------------------------------------------------------------------------
// Images
// -----------------------------------------------------------------------------

it('asks frappe to downscale an image when told to', function (): void {
    Http::fake(['*' => ($this->uploaded)(['file_name' => 'logo.png', 'file_url' => '/files/logo.png'])]);

    Erpnext::upload()
        ->fromContents('PNGBYTES', 'logo.png')
        ->public()
        ->optimize(maxWidth: 1200, maxHeight: 800)
        ->store();

    Http::assertSent(fn (Request $r): bool => multipartField($r->body(), 'optimize') === '1'
        && multipartField($r->body(), 'max_width') === '1200'
        && multipartField($r->body(), 'max_height') === '800');
});

it('recognises an image by its file name', function (): void {
    expect(File::hydrate(['name' => 'F', 'file_name' => 'logo.PNG'])->isImage())->toBeTrue()
        ->and(File::hydrate(['name' => 'F', 'file_name' => 'scan.pdf'])->isImage())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Reading back
// -----------------------------------------------------------------------------

it('builds an absolute url from the site root', function (): void {
    $file = File::hydrate(['name' => 'F', 'file_url' => '/private/files/scan.pdf']);

    expect($file->url())->toBe('https://erp.test/private/files/scan.pdf');
});

it('downloads a private file with credentials attached', function (): void {
    Http::fake(['*' => Http::response('PDF-BYTES')]);

    $bytes = File::hydrate(['name' => 'F', 'file_url' => '/private/files/scan.pdf'])->download();

    expect($bytes)->toBe('PDF-BYTES');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://erp.test/private/files/scan.pdf'
        && $r->hasHeader('Authorization', 'token k:s'));
});

// -----------------------------------------------------------------------------
// Failure
// -----------------------------------------------------------------------------

it('refuses to upload nothing', function (): void {
    Http::fake();

    expect(fn () => Erpnext::upload()->store())
        ->toThrow(ErpException::class, 'Nothing to upload');

    Http::assertNothingSent();
});

it('reports an unreadable path without reaching the network', function (): void {
    Http::fake();

    expect(fn () => Erpnext::upload()->fromPath('/no/such/file.pdf'))
        ->toThrow(ErpException::class, 'Cannot read the file to upload');

    Http::assertNothingSent();
});

it('surfaces a rejected upload with the file name', function (): void {
    Http::fake(['*' => Http::response(['exception' => 'FileTooLargeError: too big'], 413)]);

    expect(fn () => Erpnext::upload()->fromContents('BYTES', 'huge.zip')->store())
        ->toThrow(ErpException::class, 'ERPNext rejected the upload of [huge.zip]');
});

it('treats a null message as a failed upload', function (): void {
    // Frappe answers null for a non-final chunk; we never chunk, so this is a failure.
    Http::fake(['*' => Http::response(['message' => null])]);

    expect(fn () => Erpnext::upload()->fromContents('BYTES', 'scan.pdf')->store())
        ->toThrow(ErpException::class, 'returned no File document');
});
