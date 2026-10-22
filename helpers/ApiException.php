<?php

final class ApiException extends \RuntimeException
{
    public string $errCode;
    public int $httpStatus;
    public array $details;

    public function __construct(
        string $code,
        int $httpStatus,
        string $message,
        array $details = [],
    ) {
        parent::__construct($message, 0, null);
        $this->errCode = $code;
        $this->httpStatus = $httpStatus;
        $this->details = $details;
    }

    public static function from(string $code, array $details = []): self
    {
        $entry = ErrorCode::CATALOG[$code] ?? [500, 'Terjadi kesalahan'];
        $message = $entry[1];

        foreach ($details as $key => $val) {
            $message = str_replace('{' . $key . '}', (string) $val, $message);
        }

        return new self($code, $entry[0], $message, $details);
    }
}
