<?php

declare(strict_types=1);

namespace VerteraDev\TranslationClient;

use VerteraDev\TranslationClient\Exception\TranslationClientException;
use VerteraDev\TranslationLoader\TranslationManager;
use VerteraDev\TranslationLoader\Reader\TranslationReaderAbstract;
use VerteraDev\TranslationLoader\Writer\TranslationWriterAbstract;

class TranslationClient
{
    /** @var int Статус - Ожидание */
    protected const STATUS_WAITING = 1;
    /** @var int Статус - Выполнение */
    protected const STATUS_RESERVED = 2;
    /** @var int Статус - Выполнено */
    protected const STATUS_DONE = 3;

    /** @var int Интервал проверки состояний (секунды) */
    protected $checkInterval = 5;
    /** @var int Ограничение времени выполнения скрипта (секунды) */
    protected $maxExecutionTime = 900;

    /** @var string Хост TMS */
    protected $host;

    /**
     * Методы для получения переводов
     */
    /** @var string Метод: Запросить формирование файла с переводами */
    protected $importRequestMethod;
    /** @var string Метод: Проверить статус выполнения (очереди) */
    protected $importStateMethod;
    /** @var string Метод: Скачать файл с переводами */
    protected $importDownloadMethod;

    /**
     * Методы для отправки переводов
     */
    /** @var string Метод: Отправить файла с переводами */
    protected $exportUploadMethod;
    /** @var string Метод: Проверить статус выполнения (очереди) */
    protected $exportStateMethod;

    /** @var string Код доступа к TMS API */
    protected $accessToken;
    /** @var string UUID приложения на TMS */
    protected $applicationToken;

    /**
     * @param string $host
     * @param string $importRequestMethod
     * @param string $importStateMethod
     * @param string $importDownloadMethod
     * @param string $exportUploadMethod
     * @param string $exportStateMethod
     * @param string $accessToken
     * @param string $applicationToken
     */
    public function __construct(
        string $host,
        string $importRequestMethod,
        string $importStateMethod,
        string $importDownloadMethod,
        string $exportUploadMethod,
        string $exportStateMethod,
        string $accessToken,
        string $applicationToken
    ) {
        $this->host = $host;
        $this->importRequestMethod = $importRequestMethod;
        $this->importStateMethod = $importStateMethod;
        $this->importDownloadMethod = $importDownloadMethod;

        $this->exportUploadMethod = $exportUploadMethod;
        $this->exportStateMethod = $exportStateMethod;

        $this->accessToken = $accessToken;
        $this->applicationToken = $applicationToken;
    }

    /**
     * @param TranslationManager $manager
     * @param TranslationReaderAbstract $reader
     * @param TranslationWriterAbstract $writer
     * @param string $pathToExportFile Путь к временному файлу для экспорта
     * @throws TranslationClientException
     */
    public function exportTranslations(TranslationManager $manager, TranslationReaderAbstract $reader, TranslationWriterAbstract $writer, string $pathToExportFile): void
    {
        // Формирование файла с переводами
        $manager->copyTranslations($reader, $writer);

        if (!is_file($pathToExportFile)) {
            throw new TranslationClientException('Файл не найден!', ['filePath' => $pathToExportFile]);
        }
        // Отправка файла с переводами в TMS
        $processId = $this->exportSendFile($pathToExportFile);

        // Проверка выполнения экспорта переводов
        $executionTime = 0;
        while (true) {
            $state = $this->exportGetState($processId);
            if ($state == static::STATUS_DONE) {
                break;
            }
            sleep($this->checkInterval);
            $executionTime += $this->checkInterval;
            if ($executionTime >= $this->maxExecutionTime) {
                break;
            }
        }
    }

    /**
     * @param TranslationManager $manager
     * @param TranslationReaderAbstract $reader
     * @param TranslationWriterAbstract $writer
     * @param string $pathToImportFile Путь к временному файлу для импорта.
     * @throws TranslationClientException
     */
    public function importTranslations(TranslationManager $manager, TranslationReaderAbstract $reader, TranslationWriterAbstract $writer, string $pathToImportFile): void
    {
        $processId = $this->importSendRequest($manager->getLanguages());
        // Проверка выполнения экспорта переводов
        $executionTime = 0;
        while (true) {
            $state = $this->importGetState($processId);
            if ($state == static::STATUS_DONE) {
                break;
            }
            sleep($this->checkInterval);
            $executionTime += $this->checkInterval;
            if ($executionTime >= $this->maxExecutionTime) {
                break;
            }
        }

        if (is_file($pathToImportFile)) {
            throw new TranslationClientException('Файл уже существует!', ['filePath' => $pathToImportFile]);
        }

        $this->importDownloadFile($processId, $pathToImportFile);
        $manager->copyTranslations($reader, $writer);

        unlink($pathToImportFile);
    }

    /**
     * @return int ID процесса
     * @throws TranslationClientException
     */
    protected function importSendRequest(array $languages): int
    {
        $params = ['fields' => 'id'];
        $headers = [
            "Access-Token: {$this->accessToken}",
            "X-App-Token: {$this->applicationToken}"
        ];
        $data = [
            'languages' => implode(',', $languages)
        ];
        $responseData = $this->sendRequest($this->importRequestMethod, 'post', $params, $headers, $data);

        if (isset($responseData['data']['id'])) {
            return $responseData['data']['id'];
        }
        throw new TranslationClientException('Произошла ошибка при загрузке файла!', [
            'endpoint' => $this->importRequestMethod,
            'params' => $params,
            'headers' => $headers,
        ]);
    }

    /**
     * @param int $processId ID процесса
     * @return int Статус процесса
     * @throws TranslationClientException
     */
    protected function importGetState(int $processId): int
    {
        $params = [
            'id' => $processId,
            'fields' => 'id,state'
        ];
        $headers = [
            "Access-Token: {$this->accessToken}",
            "X-App-Token: {$this->applicationToken}"
        ];
        $responseData = $this->sendRequest($this->importStateMethod, 'get', $params, $headers);

        if (isset($responseData['data']['state']['id'])) {
            return $responseData['data']['state']['id'];
        }
        throw new TranslationClientException('Произошла ошибка при запросе статуса!', [
            'endpoint' => $this->importStateMethod,
            'params' => $params,
            'headers' => $headers,
        ]);
    }

    /**
     * @param int $processId ID процесса
     * @param string $pathToImportFile Путь к временному файлу для импорта.
     */
    protected function importDownloadFile(int $processId, string $pathToImportFile): void
    {
        $url = "{$this->host}{$this->importDownloadMethod}?id={$processId}";

        $opts = array(
            'http'=>array(
                'method'=>"GET",
                'header'=>"Access-Token: {$this->accessToken}\r\n" .
                    "X-App-Token: {$this->applicationToken}\r\n"
            )
        );

        $context = stream_context_create($opts);
        $fp = fopen($url, 'r', false, $context);

        file_put_contents($pathToImportFile, $fp);
    }

    /**
     * @param string $pathToExportFile
     * @return int ID процесса
     * @throws TranslationClientException
     */
    protected function exportSendFile(string $pathToExportFile): int
    {
        $params = ['fields' => 'id'];
        $headers = [
            "Access-Token: {$this->accessToken}",
            "X-App-Token: {$this->applicationToken}"
        ];
        $data = ['file' => curl_file_create($pathToExportFile)];
        $responseData = $this->sendRequest($this->exportUploadMethod, 'post', $params, $headers, $data);

        if (isset($responseData['data']['id'])) {
            return $responseData['data']['id'];
        }
        throw new TranslationClientException('Произошла ошибка при загрузке файла!', [
            'endpoint' => $this->exportUploadMethod,
            'params' => $params,
            'headers' => $headers,
            'data' => $data
        ]);
    }

    /**
     * @param int $processId ID процесса
     * @return int
     * @throws TranslationClientException
     */
    protected function exportGetState(int $processId): int
    {
        $params = [
            'id' => $processId,
            'fields' => 'id,state'
        ];
        $headers = [
            "Access-Token: {$this->accessToken}",
            "X-App-Token: {$this->applicationToken}"
        ];
        $responseData = $this->sendRequest($this->exportStateMethod, 'get', $params, $headers);

        if (isset($responseData['data']['state']['id'])) {
            return $responseData['data']['state']['id'];
        }
        throw new TranslationClientException('Произошла ошибка при запросе статуса!', [
            'endpoint' => $this->exportStateMethod,
            'params' => $params,
            'headers' => $headers,
        ]);
    }

    /**
     * @param string $endpoint
     * @param string $method
     * @param array $params
     * @param array $headers
     * @param array $data
     * @return mixed
     * @throws TranslationClientException
     */
    protected function sendRequest(string $endpoint, string $method = 'get', array $params = [], array $headers = [], array $data = [])
    {
        $url = $this->host . $endpoint;
        if (!empty($params)) {
            $url = $url . '?' . http_build_query($params);
        }

        $curl = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true, // return the transfer as a string of the return value
            CURLOPT_TIMEOUT => 0, // The maximum number of seconds to allow cURL functions to execute.
            CURLOPT_POST => ($method == 'post'), // This line must place before CURLOPT_POSTFIELDS
        ];
        if (!empty($data)) {
            $options[CURLOPT_POSTFIELDS] = $data;
        }
        curl_setopt_array($curl, $options);
        if (!empty($headers)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($curl);
        $info = curl_getinfo($curl);
        $errno = curl_errno($curl);
        if ($errno) {
            throw new TranslationClientException('An error occurred while sending a request to TMS!', [
                'curlErrno' => $errno,
                'endpoint' => $endpoint,
                'method' => $method,
                'params' => $params,
                'headers' => $headers,
                'http_code' => $info['http_code'],
            ]);
        }
        curl_close($curl);

        if ($info['http_code'] == 200) {
            return json_decode($response, true);
        }

        throw new TranslationClientException('Произошла ошибка при отправке запроса в TMS!', [
            'endpoint' => $endpoint,
            'method' => $method,
            'params' => $params,
            'headers' => $headers,
            'http_code' => $info['http_code'],
        ]);
    }

    /**
     * @param int $interval
     */
    public function setCheckInterval(int $interval): void
    {
        $this->checkInterval = $interval;
    }

    /**
     * @return int
     */
    public function getCheckInterval(): int
    {
        return $this->checkInterval;
    }

    /**
     * @param int $maxExecutionTime
     */
    public function setMaxExecutionTime(int $maxExecutionTime): void
    {
        $this->maxExecutionTime = $maxExecutionTime;
    }

    /**
     * @return int
     */
    public function getMaxExecutionTime(): int
    {
        return $this->maxExecutionTime;
    }
}
