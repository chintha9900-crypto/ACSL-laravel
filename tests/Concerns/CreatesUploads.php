<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Builds uploads backed by real files, so content-type detection inspects the
 * actual bytes. (Laravel's fake files report a MIME type derived from the
 * filename, which cannot exercise magic-byte checks.)
 */
trait CreatesUploads
{
    protected function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    protected function pngContent(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    /**
     * A real uploaded file that claims `$claimedMime`, whatever its bytes are.
     */
    protected function realUpload(string $name, string $content, string $claimedMime = 'application/pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aci-upload-');
        file_put_contents($path, $content);

        $this->beforeApplicationDestroyed(function () use ($path): void {
            @unlink($path);
        });

        return new UploadedFile($path, $name, $claimedMime, null, true);
    }

    protected function pdfUpload(string $name = 'employment-letter.pdf'): UploadedFile
    {
        return $this->realUpload($name, $this->pdfContent());
    }

    protected function pngUpload(string $name = 'photo.png'): UploadedFile
    {
        return $this->realUpload($name, $this->pngContent(), 'image/png');
    }
}
