<?php
declare(strict_types=1);

namespace App\Core;

/** Thrown anywhere; the front controller turns it into a JSON error response. */
class ApiException extends \Exception
{
    public int $status;
    public array $errors;

    public function __construct(string $message, int $status = 400, array $errors = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errors = $errors;
    }
}
