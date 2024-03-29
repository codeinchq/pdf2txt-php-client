<?php
/*
 * Copyright 2024 Code Inc. <https://www.codeinc.co>
 *
 * Use of this source code is governed by an MIT-style
 * license that can be found in the LICENSE file or at
 * https://opensource.org/licenses/MIT.
 */

declare(strict_types=1);

namespace CodeInc\Pdf2TxtClient;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Message\MultipartStream\MultipartStreamBuilder;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Class Pdf2TxtClient
 *
 * @package CodeInc\Pdf2TxtClient
 * @link https://github.com/codeinchq/pdf2txt
 * @link https://github.com/codeinchq/pdf2txt-php-client
 * @license https://opensource.org/licenses/MIT MIT
 */
class Pdf2TxtClient
{
    public function __construct(
        private readonly string $baseUrl,
        private ClientInterface|null $client = null,
        private StreamFactoryInterface|null $streamFactory = null,
        private RequestFactoryInterface|null $requestFactory = null,
    ) {
        $this->client ??= Psr18ClientDiscovery::find();
        $this->streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
        $this->requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
    }

    /**
     * Converts a PDF to text using streams and the PDF2TEXT API.
     *
     * @param StreamInterface|resource|string $stream The PDF content.
     * @param ConvertOptions $options The convert options.
     * @return StreamInterface
     * @throws Exception
     */
    public function extract(mixed $stream, ConvertOptions $options = new ConvertOptions()): StreamInterface
    {
        try {
            // building the multipart stream
            $multipartStreamBuilder = (new MultipartStreamBuilder($this->streamFactory))
                ->addResource(
                    'file',
                    $stream,
                    [
                        'filename' => 'file.pdf',
                        'headers'  => ['Content-Type' => 'application/pdf']
                    ]
                )
                ->addResource('firstPage', (string)$options->firstPage)
                ->addResource('normalizeWhitespace', (string)$options->normalizeWhitespace)
                ->addResource('format', $options->format->name);

            if ($options->lastPage !== null) {
                $multipartStreamBuilder->addResource('lastPage', (string)$options->lastPage);
            }
            if ($options->password !== null) {
                $multipartStreamBuilder->addResource('password', (string)$options->password);
            }

            // sending the request
            $response = $this->client->sendRequest(
                $this->requestFactory
                    ->createRequest("POST", $this->getConvertEndpointUri())
                    ->withHeader(
                        "Content-Type",
                        "multipart/form-data; boundary={$multipartStreamBuilder->getBoundary()}"
                    )
                    ->withBody($multipartStreamBuilder->build())
            );
        } catch (ClientExceptionInterface $e) {
            throw new Exception(
                message: "An error occurred while sending the request to the PDF2TEXT API",
                code: Exception::ERROR_REQUEST,
                previous: $e
            );
        }

        // checking the response
        if ($response->getStatusCode() !== 200) {
            throw new Exception(
                message: "The PDF2TEXT API returned an error {$response->getStatusCode()}",
                code: Exception::ERROR_RESPONSE,
                previous: new Exception((string)$response->getBody())
            );
        }

        // returning the response
        return $response->getBody();
    }

    /**
     * Processes a JSON response from the PDF2TEXT API.
     *
     * @param StreamInterface $response
     * @return array
     * @throws JsonException
     */
    public function processJsonResponse(StreamInterface $response): array
    {
        return json_decode(
            json: (string)$response,
            associative: true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    /**
     * Opens a local file and creates a stream from it.
     *
     * @param string $path The path to the file.
     * @param string $openMode The mode used to open the file.
     * @return StreamInterface
     * @throws Exception
     */
    public function createStreamFromFile(string $path, string $openMode = 'r'): StreamInterface
    {
        $f = fopen($path, $openMode);
        if ($f === false) {
            throw new Exception("The file '$path' could not be opened", Exception::ERROR_FILE_OPEN);
        }

        return $this->streamFactory->createStreamFromResource($f);
    }

    /**
     * Saves a stream to a local file.
     *
     * @param StreamInterface $stream
     * @param string $path The path to the file.
     * @param string $openMode The mode used to open the file.
     * @throws Exception
     */
    public function saveStreamToFile(StreamInterface $stream, string $path, string $openMode = 'w'): void
    {
        $f = fopen($path, $openMode);
        if ($f === false) {
            throw new Exception("The file '$path' could not be opened", Exception::ERROR_FILE_OPEN);
        }

        if (stream_copy_to_stream($stream->detach(), $f) === false) {
            throw new Exception("The stream could not be copied to the file '$path'", Exception::ERROR_FILE_WRITE);
        }

        fclose($f);
    }

    /**
     * Returns the convert endpoint URI.
     *
     * @return string
     */
    private function getConvertEndpointUri(): string
    {
        $url = $this->baseUrl;
        if (!str_ends_with($url, '/')) {
            $url .= '/';
        }
        return "{$url}extract";
    }
}
