<?php

namespace Kayedspace\Erpnext\Facades;

use Illuminate\Support\Facades\Facade;
use Kayedspace\Erpnext\Client\Doctype;
use Kayedspace\Erpnext\Client\ErpClient;
use Kayedspace\Erpnext\Client\ErpQuery;
use Kayedspace\Erpnext\Connection;
use Kayedspace\Erpnext\Files\PendingUpload;

/**
 * @method static Doctype doctype(string $doctype)
 * @method static ErpQuery query(string $doctype)
 * @method static array<string, mixed>|null find(string $doctype, string $name, bool $expandLinks = false)
 * @method static array<string, mixed> findOrFail(string $doctype, string $name, bool $expandLinks = false)
 * @method static array<string, mixed> create(string $doctype, array<string, mixed> $data, ?string $uniqueBy = null)
 * @method static array<string, mixed> update(string $doctype, string $name, array<string, mixed> $data, ?string $uniqueBy = null)
 * @method static void delete(string $doctype, string $name)
 * @method static array<string, mixed> call(string $doctype, string $name, string $method, array<string, mixed> $args = [])
 * @method static array<int, array<string, mixed>> search(string $doctype, array<string, mixed> $parameters)
 * @method static int count(string $doctype, array<string, mixed> $parameters = [])
 * @method static PendingUpload upload()
 * @method static array<string, mixed> uploadFile(array<string, mixed> $form, string $contents, string $filename)
 * @method static string downloadFile(string $fileUrl)
 * @method static Connection connection()
 *
 * @see ErpClient
 */
class Erpnext extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ErpClient::class;
    }
}
