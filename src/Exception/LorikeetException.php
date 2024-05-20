<?php

declare(strict_types=1);

namespace NestboxPHP\Lorikeet\Exception;

use NestboxPHP\Nestbox\Exception\NestboxException;

class LorikeetException extends NestboxException
{
    public function __construct(string $message = "", int $code = 0, $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
