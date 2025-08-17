<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Level;
use Psr\Http\Message\ResponseInterface;

readonly class DownloadManager
{

    public function __construct(
        private Client $http,
        private Filesystem $filesystem,
        private Logger $logger,
        private string $dirTmp,
        private string $dirDownload,
        private string $dirLog,
        private int $maxRetries,
        private int $concurrency,
        private int $maxDelaysMs,
        private int $baseDelaysMs,
    ) {
        $this->logger->pushHandler(new StreamHandler($this->dirLog . '/app.log', Level::Debug));
    }

    public function downloadMany(array $urls, callable $progress): void
    {
        $requestsUrls = function () use ($urls) {
            foreach ($urls as $url) {
                yield function () use ($url) {
                    return $this->download($url);
                };
            }
        };

        $pool = new Pool($this->http, $requestsUrls(), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function ($response, $index) use ($progress, $urls) {
                // this is delivered each successful response
                $progress(sprintf('Completed: %s', $urls[$index]));
            },
            'rejected' => function (\Throwable $reason, $index) use ($progress, $urls) {
                // this is delivered each failed request
                $progress(sprintf('Failed: %s | %s', $urls[$index], $reason->getMessage()));
                $this->logger->error('failed download', [
                    'url' => $urls[$index],
                    'msg' => $reason->getMessage(),
                ]);
            },
        ]);

        // Trigger the pool and block until done
        $pool->promise()->wait();
    }

    private function download(string $url): Coroutine
    {
        return new Coroutine(function () use ($url) {
            try {
                $filename = $this->generateFileName($url);
                $tmpPath = $this->dirTmp . '/' . $filename;
                $finalPath = $this->dirDownload . '/' . $filename;
                $attempt = 0;

                if ($this->filesystem->exists($finalPath)) {
                    $this->logger->info('Already completed', [
                        'url' => $url,
                        'file' => $finalPath,
                    ]);

                    return yield Create::promiseFor(null);
                }

                $this->filesystem->touch($tmpPath);

                while ($attempt < $this->maxRetries) {
                    $resumeFrom = file_exists($tmpPath) ? filesize($tmpPath) : 0;
                    $headers = $resumeFrom > 0 ?
                        [
                            'Range' => "bytes={$resumeFrom}-",
                        ] : [];

                    $this->logger->info('Requesting', [
                        'attempt' => $attempt,
                        'url' => $url,
                        'range' => $headers['Range'] ?? 'full',
                    ]);

                    try {
                        $response = yield $this->http->requestAsync('GET', $url, [
                            'headers' => $headers,
                            'stream' => true,
                        ]);

                        /** @var StreamInterface $body */
                        $body = $response->getBody();
                        $fh = fopen($tmpPath, 'ab');
                        while (!$body->eof()) {
                            $chunk = $body->read(1048576); // 1MB chunks
                            if ($chunk === '') {
                                usleep(10000);
                                continue;
                            }
                            fwrite($fh, $chunk);
                        }
                        fclose($fh);

                        $status = $response->getStatusCode();
                        $total = match ($status) {
                            HttpResponse::HTTP_OK => $this->fullResponseSize($response),
                            HttpResponse::HTTP_PARTIAL_CONTENT => $this->partialResponseSize($response),
                            default => null,
                        };

                        if (!$total) {
                            $this->logger->error('Cant determine file total size', [
                                'http_code' => $status,
                                'attempt' => $attempt,
                                'url' => $url,
                                'range' => $headers['Range'] ?? 'full',
                            ]);

                            return yield new RejectedPromise(
                                new \RuntimeException('Cant determine total size. Url: ' . $url),
                            );
                        }

                        // Verify completion
                        $current = filesize($tmpPath) ?: 0;
                        if ($current !== $total) {
                            throw new \RuntimeException("Partial content. Retry: {$current}/{$total}");
                        }

                        $this->filesystem->rename($tmpPath, $finalPath, true);
                        $this->logger->info('File completed', [
                            'file' => $finalPath,
                        ]);

                        return $finalPath; // success
                    } catch (\Throwable $e) {
                        $delay = $this->calculateDelay($attempt);
                        $attempt++;
                        $this->logger->warning('retrying', [
                            'url' => $url,
                            'attempt' => $attempt,
                            'delay_ms' => $delay,
                            'error' => $e->getMessage(),
                        ]);
                        usleep($delay * 1000);
                    }
                }

                return yield new RejectedPromise(new \RuntimeException('Exhausted retries'));
            } catch (\Throwable $e) {
                return yield new RejectedPromise($e);
            }
        });
    }

    private function generateFileName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return basename($path);
    }

    private function fullResponseSize(ResponseInterface $response): ?int
    {
        $len = $response->getHeaderLine('Content-Length');

        return $len !== '' ? (int)$len : null;
    }

    private function partialResponseSize(ResponseInterface $response): ?int
    {
        $cr = $response->getHeaderLine('Content-Range');
        if (!$cr) {
            return null; // no Content-Range header
        }
dd($cr);
        [, $total] = explode('/', $cr, 2);   // "bytes 0-99", "150177558"

        return (int)$total;
    }

    // Exponential backoff with jitter
    private function calculateDelay(int $attempt): int
    {
        $exp = min($this->maxDelaysMs, $this->baseDelaysMs * (2 ** ($attempt)));

        $half = intdiv($exp, 2);
        $jitter = random_int(-$half, $half);

        return max($this->baseDelaysMs, min($this->maxDelaysMs, $exp + $jitter));
    }

}
