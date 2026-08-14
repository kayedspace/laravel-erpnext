<?php

namespace Kayedspace\Erpnext\Exceptions;

use Exception;

/**
 * Base for every failure originating from ERPNext or from this package's own
 * validation of what it is about to send.
 */
class ErpException extends Exception {}
