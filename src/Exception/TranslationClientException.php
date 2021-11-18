<?php

declare(strict_types=1);

namespace VerteraDev\TranslationClient\Exception;

use Exception;

class TranslationClientException extends Exception
{
    /** @var array */
    public $errorInfo = [];

    /**
     * @param string $message
     * @param array $errorInfo
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct(string $message, array $errorInfo = [], int $code = 0, Exception $previous = null)
    {
        $this->errorInfo = $errorInfo;
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Translation Client Exception';
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return parent::__toString() . PHP_EOL
            . 'Additional Information:' . PHP_EOL . print_r($this->errorInfo, true);
    }
}
