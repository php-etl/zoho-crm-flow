<?php

declare(strict_types=1);

namespace Kiboko\Component\Flow\ZohoCRM;

use Kiboko\Component\Bucket\AcceptanceResultBucket;
use Kiboko\Component\Bucket\EmptyResultBucket;
use Kiboko\Component\Bucket\RejectionResultBucket;
use Kiboko\Component\Flow\ZohoCRM\Client\ApiRateExceededException;
use Kiboko\Component\Flow\ZohoCRM\Client\BadRequestException;
use Kiboko\Component\Flow\ZohoCRM\Client\Client;
use Kiboko\Component\Flow\ZohoCRM\Client\ForbiddenException;
use Kiboko\Component\Flow\ZohoCRM\Client\InternalServerErrorException;
use Kiboko\Component\Flow\ZohoCRM\Client\NoContentException;
use Kiboko\Component\Flow\ZohoCRM\Client\NotFoundException;
use Kiboko\Component\Flow\ZohoCRM\Client\RequestEntityTooLargeException;
use Kiboko\Contract\Mapping\CompiledMapperInterface;
use Kiboko\Contract\Pipeline\TransformerInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class GetOrderLookup implements TransformerInterface
{
    public function __construct(
        private Client $client,
        private \Psr\Log\LoggerInterface $logger,
        private CacheInterface $cache,
        private CompiledMapperInterface $mapper,
        private string $subjectMappingField,
        private string $storeMappingField,
    ) {
    }

    public function transform(): \Generator
    {
        $line = yield new EmptyResultBucket();

        /* @phpstan-ignore-next-line */
        while (true) {
            try {
                $encodingKey = base64_encode(sprintf('order.%s.%s', $line[$this->subjectMappingField], $line[$this->storeMappingField]));
                $lookup = $this->cache->get($encodingKey);

                if (null === $lookup) {
                    $lookup = $this->client->searchOrder(subject: $line[$this->subjectMappingField], store: $line[$this->storeMappingField]);

                    $this->cache->set($encodingKey, $lookup);
                }

                $result = $lookup;
                $lookup = $this->cache->get(sprintf('order.%s', $result['id']));
                if (null === $lookup) {
                    $lookup = $this->client->getOrder(id: $result['id']);

                    $this->cache->set(sprintf('order.%s', $result['id']), $lookup);
                }

                $output = ($this->mapper)($lookup, $line);

                $line = yield new AcceptanceResultBucket($output);
            } catch (NoContentException) {
                $line = yield new AcceptanceResultBucket($line);
            } catch (ApiRateExceededException|InternalServerErrorException $exception) {
                $this->logger->critical($exception->getMessage(), ['exception' => $exception, 'item' => $line]);
                yield new RejectionResultBucket(
                    'It seems that the API request limit has been reached or that there is a problem with the server. Please, retry later.',
                    $exception,
                    $line
                );
            } catch (BadRequestException|RequestEntityTooLargeException $exception) {
                $this->logger->error($exception->getMessage(), ['exception' => $exception, 'item' => $line]);
                $line = yield new RejectionResultBucket(
                    'It seems that the format of the request is not correct or it is too long. Please check your request and try again.',
                    $exception,
                    $line
                );

                continue;
            } catch (ForbiddenException|NotFoundException $exception) {
                $this->logger->error($exception->getMessage(), ['exception' => $exception, 'item' => $line]);
                $line = yield new RejectionResultBucket(
                    'It seems that the resource does not exist or that you do not have the rights to access this resource. Please check your rights and try again.',
                    $exception,
                    $line
                );
                continue;
            }
        }
    }
}
