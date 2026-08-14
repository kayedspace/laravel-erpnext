<?php

namespace Kayedspace\Erpnext\Exceptions;

class DocumentNotFoundException extends ErpException
{
    public static function for(string $doctype, string $name): self
    {
        return new self("ERPNext {$doctype} [{$name}] was not found.");
    }
}
