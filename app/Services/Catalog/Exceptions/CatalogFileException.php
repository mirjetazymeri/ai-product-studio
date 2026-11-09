<?php

namespace App\Services\Catalog\Exceptions;

use RuntimeException;

/**
 * A problem with an uploaded file. The message is written to be shown to the user.
 */
final class CatalogFileException extends RuntimeException {}
