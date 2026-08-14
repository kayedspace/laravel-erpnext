<?php

namespace Kayedspace\Erpnext\Files;

use Illuminate\Http\Client\ConnectionException;
use Kayedspace\Erpnext\Client\ErpClient;
use Kayedspace\Erpnext\Documents\File;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * A file on its way to ERPNext.
 *
 * Frappe's upload endpoint takes a dozen loosely related form fields and silently
 * defaults several of them. This builder makes the choices explicit and refuses to send
 * a request that could not possibly work.
 *
 * ```php
 * Erpnext::upload()
 *     ->fromPath(storage_path('app/scan.pdf'))
 *     ->public()
 *     ->attachTo('Sales Invoice', 'ACC-SINV-2025-00001', 'custom_scan')
 *     ->store();
 * ```
 */
class PendingUpload
{
    private ?string $contents = null;

    private ?string $filename = null;

    /** Frappe's own default. Uploads are private unless you say otherwise. */
    private bool $private = true;

    private ?string $doctype = null;

    private ?string $docname = null;

    private ?string $fieldname = null;

    private ?string $folder = null;

    private bool $optimize = false;

    private ?int $maxWidth = null;

    private ?int $maxHeight = null;

    private bool $setsField = true;

    public function __construct(private readonly ErpClient $client) {}

    /**
     * @throws ErpException
     */
    public function fromPath(string $path): static
    {
        if (! is_readable($path)) {
            throw new ErpException("Cannot read the file to upload at [{$path}].");
        }

        $this->contents = (string) file_get_contents($path);
        $this->filename ??= basename($path);

        return $this;
    }

    public function fromContents(string $contents, string $filename): static
    {
        $this->contents = $contents;
        $this->filename = $filename;

        return $this;
    }

    /**
     * Override the name the file is stored under, whatever it was called on disk.
     */
    public function as(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Reachable only with credentials. This is the default, and Frappe's.
     */
    public function private(): static
    {
        $this->private = true;

        return $this;
    }

    /**
     * Served to anyone with the URL. Choose deliberately: a public file is public to
     * the whole internet, not merely to logged-in users.
     */
    public function public(): static
    {
        $this->private = false;

        return $this;
    }

    /**
     * Attach the file to a document, optionally into a specific Attach field.
     */
    public function attachTo(string $doctype, string $docname, ?string $fieldname = null): static
    {
        $this->doctype = $doctype;
        $this->docname = $docname;
        $this->fieldname = $fieldname;

        return $this;
    }

    public function toFolder(string $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    /**
     * Ask Frappe to downscale the image server-side. Ignored for non-images.
     */
    public function optimize(?int $maxWidth = null, ?int $maxHeight = null): static
    {
        $this->optimize = true;
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;

        return $this;
    }

    /**
     * Leave the target document's field untouched.
     *
     * By default {@see store()} writes `file_url` into the Attach field named by
     * {@see attachTo()}, because Frappe does not: `upload_file` records the link on the
     * File doc, but only the desk UI writes the value back onto the parent. Without
     * that write the attachment exists and the field still reads empty.
     */
    public function withoutSettingField(): static
    {
        $this->setsField = false;

        return $this;
    }

    /**
     * Perform the upload and return the resulting File document.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function store(): File
    {
        if ($this->contents === null || $this->filename === null) {
            throw new ErpException('Nothing to upload: call fromPath() or fromContents() first.');
        }

        if ($this->fieldname !== null && ($this->doctype === null || $this->docname === null)) {
            throw new ErpException('A field name is meaningless without a doctype and document to attach to.');
        }

        $form = array_filter([
            'is_private' => $this->private ? 1 : 0,
            'doctype' => $this->doctype,
            'docname' => $this->docname,
            'fieldname' => $this->fieldname,
            'folder' => $this->folder,
            'optimize' => $this->optimize ? 1 : null,
            'max_width' => $this->maxWidth,
            'max_height' => $this->maxHeight,
        ], fn (mixed $value): bool => $value !== null);

        $file = File::hydrate($this->client->uploadFile($form, $this->contents, $this->filename));

        if ($this->setsField && $this->fieldname !== null && filled($file->fileUrl())) {
            $this->client->update($this->doctype, $this->docname, [$this->fieldname => $file->fileUrl()]);
        }

        return $file;
    }
}
