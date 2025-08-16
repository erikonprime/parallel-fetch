<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\RejectedPromise;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

readonly class DownloadManager
{

    public function __construct(
        private Client $http,
        private Filesystem $filesystem,
        private string $dirTmp,
        private string $dirDownload,
        private int $maxRetries,
        private int $concurrency,
    ) {}

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
                    return yield Create::promiseFor(null);
                }

                $this->filesystem->touch($tmpPath);

                while ($attempt < $this->maxRetries) {
                    $resumeFrom = filesize($tmpPath) ?: 0;
                    $headers = $resumeFrom > 0 ?
                        [
                            'Range' =>
                                "bytes={$resumeFrom}-",
                        ] : [];

                    try {
                        $response = yield $this->http->requestAsync('GET', $url, [
                            'headers' => $headers,
                            'stream' => true,
                        ]);

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

                        // Verify completion
                        $current = filesize($tmpPath) ?: 0;
                        if ($current !== $total) {
                            throw new \RuntimeException("Partial content: {$current}/{$total}");
                        }
                        // Move to completed
                        $this->filesystem->rename($tmpPath, $finalPath, true);

                        return $finalPath; // success
                    } catch (\Throwable $e) {
                        $attempt++;
                        usleep(10000);
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

        return sprintf('%s_%s', uniqid(), basename($path));
    }

    private function fullResponseSize($response): ?int
    {
        $len = $response->getHeaderLine('Content-Length');

        return $len !== '' ? (int)$len : null;
    }

    private function partialResponseSize($response): ?int
    {
        $cr = $response->getHeaderLine('Content-Range');
        if (!$cr) {
            return null; // no Content-Range header
        }

        [$range, $total] = explode('/', $cr, 2);   // "bytes 0-99", "150177558"
        [, $positions] = explode(' ', $range, 2); // "0-99"
        [$start, $end] = explode('-', $positions, 2);

        if ((int)$end < (int)$total) {
            throw new \RuntimeException("Partial content: {$end}/{$total}");
        }

        return (int)$end;
    }

}
