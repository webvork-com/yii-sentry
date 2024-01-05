<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Sentry\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcher;
use Sentry\Integration\RequestFetcherInterface;

class YiiRequestFetcher implements RequestFetcherInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @psalm-suppress MixedInferredReturnType
     */
    public function fetchRequest(): ?ServerRequestInterface
    {
        if ($this->container->has(ServerRequestInterface::class)) {
            /** @psalm-suppress  MixedAssignment */
            $result = $this->container->get(ServerRequestInterface::class);

            if ($result instanceof ServerRequestInterface) {
                return $result;
            }
        }

        return (new RequestFetcher())->fetchRequest();
    }
}
