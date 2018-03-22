<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Exception;

class ApplicationException extends \Exception
{
    /** @var array */
    protected $data;

    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, array $data = [])
    {
        $this->setData($data);
        parent::__construct($message, $code, $previous);
    }
    /**
     * @param array $data
     */
    public function setData(array $data) : void
    {
        $this->data = $data;
    }
    /**
     * @return array
     */
    public function getData() : array
    {
        return $this->data;
    }
}
