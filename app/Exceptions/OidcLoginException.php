<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised for any failure in the OIDC login flow. The message is for the log
 * only; users always see the generic "OIDC login failed" error.
 */
class OidcLoginException extends RuntimeException {}
