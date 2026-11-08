<?php

declare(strict_types=1);

namespace Gkeepapi\Tests\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;

/**
 * PSR-18 test double: replays canned responses and records requests. Never touches the network.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @param array<string, string> $headers */
    public function queueBody(string $body, int $status = 200, array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    public function queueFailure(ClientExceptionInterface $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('No response queued');
        }
        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /**
     * Form fields of the n-th recorded request.
     *
     * @return array<string, string>
     */
    public function form(int $index = 0): array
    {
        parse_str((string) $this->requests[$index]->getBody(), $fields);

        /** @var array<string, string> $fields */
        return $fields;
    }
}
