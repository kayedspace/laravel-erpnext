<?php

namespace Kayedspace\Erpnext\Documents;

use Illuminate\Http\Client\ConnectionException;
use Kayedspace\Erpnext\Exceptions\ErpException;

/**
 * Frappe's File doctype: every upload, attachment and image in the site is one of these.
 *
 * A File is a real document, so it can be queried, moved between folders and deleted
 * like anything else. What makes it special is `file_url`, which is where the bytes
 * actually live.
 */
class File extends Document
{
    public static function doctype(): string
    {
        return 'File';
    }

    public function fileName(): ?string
    {
        return $this->get('file_name');
    }

    /**
     * The site-relative path to the bytes: `/files/…` when public,
     * `/private/files/…` when not.
     */
    public function fileUrl(): ?string
    {
        return $this->get('file_url');
    }

    /**
     * Frappe uploads are private unless told otherwise, so this is true more often
     * than people expect.
     */
    public function isPrivate(): bool
    {
        return (bool) $this->get('is_private', 0);
    }

    public function fileSize(): int
    {
        return (int) $this->get('file_size', 0);
    }

    public function fileType(): ?string
    {
        return $this->get('file_type');
    }

    public function contentHash(): ?string
    {
        return $this->get('content_hash');
    }

    public function folder(): ?string
    {
        return $this->get('folder');
    }

    public function attachedToDoctype(): ?string
    {
        return $this->get('attached_to_doctype');
    }

    public function attachedToName(): ?string
    {
        return $this->get('attached_to_name');
    }

    public function attachedToField(): ?string
    {
        return $this->get('attached_to_field');
    }

    public function isAttached(): bool
    {
        return filled($this->attachedToDoctype()) && filled($this->attachedToName());
    }

    public function isImage(): bool
    {
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|svg|avif|bmp|ico)$/i', (string) $this->fileName());
    }

    /**
     * The fully qualified URL. Note that a private file still needs credentials to
     * fetch — a browser hitting this URL unauthenticated gets a permission error, not
     * the bytes. Use {@see download()} for that.
     */
    public function url(): ?string
    {
        $path = $this->fileUrl();

        return $path === null ? null : static::client()->connection()->baseUrl.$path;
    }

    /**
     * Fetch the bytes, authenticating as the configured user. Works for private files.
     *
     * @throws ConnectionException
     * @throws ErpException
     */
    public function download(): string
    {
        $path = $this->fileUrl() ?? throw new ErpException(
            'This File has no file_url, so there is nothing to download.'
        );

        return static::client()->downloadFile($path);
    }
}
