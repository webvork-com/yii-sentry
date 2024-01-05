<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Sentry;

use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Sentry\SentrySdk;

class GuzzleMiddlewareFactory
{
    private const MAX_LOG_BODY_IN_CHARS = 200;

    private int $maxBody;

    public function __construct(private LoggerInterface $logger, YiiSentryConfig $config)
    {
        $this->maxBody = $config->getMaxGuzzleBodyTrace() ?? self::MAX_LOG_BODY_IN_CHARS;
    }

    public function factory(callable $handler): callable
    {
        return function (RequestInterface $srcRequest, array $options) use ($handler) {
            $traceHeader = SentrySdk::getCurrentHub()->getSpan()?->toTraceparent();
            $baggage = SentrySdk::getCurrentHub()->getSpan()?->toBaggage();
            if ($traceHeader) {
                $request = $srcRequest->withAddedHeader('sentry-trace', $traceHeader);
                if ($baggage) {
                    $request = $request->withAddedHeader('baggage', $baggage);
                }
            } else {
                $request = clone $srcRequest;
            }

            $startTime = microtime(true);
            $path = $request->getMethod() . ':' . $request->getUri()->__toString();
            $requestBody = $request->getBody()->isReadable() ? $request->getBody()->getContents() : '[not readable]';
            if (mb_strwidth($requestBody) > $this->maxBody) {
                $requestContentBody = mb_strimwidth($requestBody, 0, $this->maxBody, '...');
            } else {
                $requestContentBody = $requestBody;
            }
            /** @var PromiseInterface $response */
            $response = $handler($request, $options);

            $requestMethod = $request->getMethod();
            $requestHeaders = $request->getHeaders();

            return $response->then(
                function (ResponseInterface $promiseResponse) use (
                    $startTime,
                    $requestContentBody,
                    $path,
                    $requestHeaders,
                    $requestMethod,
                ) {
                    $responseContentBody = $this->getResponseContentBody($promiseResponse);
                    $logContext = [
                        'time' => $startTime,
                        'elapsed' => microtime(true) - $startTime,
                        'category' => 'guzzle.request',
                        'method' => $requestMethod,
                        'request_headers' => $requestHeaders,
                        'response_headers' => $promiseResponse->getHeaders(),
                        'request_body' => $requestContentBody,
                        'response_body' => $responseContentBody,
                    ];
                    $this->logger->info($path, $logContext);

                    return $promiseResponse;
                },
                function (Exception $e) use (
                    $startTime,
                    $requestContentBody,
                    $path,
                ) {
                    if ($e instanceof RequestException) {
                        $responseContentBody = $this->getResponseContentBody($e->getResponse());
                        $logContext = [
                            'time' => $startTime,
                            'elapsed' => microtime(true) - $startTime,
                            'category' => 'guzzle.request',
                            'method' => $e->getRequest()->getMethod(),
                            'request_headers' => $e->getRequest()->getHeaders(),
                            'response_headers' => $e->getResponse()?->getHeaders(),
                            'request_body' => $requestContentBody,
                            'response_body' => $responseContentBody,
                            'code' => $e->getCode(),
                            'path' => $path,
                            'exception' => $e,
                        ];
                    } elseif ($e instanceof ConnectException) {
                        $logContext = [
                            'time' => $startTime,
                            'elapsed' => microtime(true) - $startTime,
                            'category' => 'guzzle.request',
                            'method' => $e->getRequest()->getMethod(),
                            'request_headers' => $e->getRequest()->getHeaders(),
                            'request_body' => $requestContentBody,
                            'code' => $e->getCode(),
                            'path' => $path,
                            'exception' => $e,
                        ];
                    } else {
                        $logContext = [
                            'time' => $startTime,
                            'elapsed' => microtime(true) - $startTime,
                            'category' => 'guzzle.request',
                            'request_body' => $requestContentBody,
                            'path' => $path,
                            'exception' => $e,
                        ];
                    }

                    $this->logger->warning($e->getMessage(), $logContext);
                }
            );
        };
    }

    protected function getResponseContentBody(?ResponseInterface $response): string
    {
        if ($response?->getBody()?->isReadable()) {
            $responseBody = $response?->getBody()->getContents();
            $response?->getBody()->rewind();
        } else {
            $responseBody = '[not readable]';
        }

        if (mb_strwidth($responseBody) > $this->maxBody) {
            $responseContentBody = mb_strimwidth($responseBody, 0, $this->maxBody, '...');
        } else {
            $responseContentBody = $responseBody;
        }

        return $responseContentBody;
    }
}
