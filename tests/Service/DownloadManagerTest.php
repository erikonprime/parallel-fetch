<?php

namespace App\Tests\Service;

use App\Service\DownloadManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use org\bovigo\vfs\vfsStream;

class DownloadManagerTest extends TestCase
{
    private Filesystem $filesystem;
    private Logger $logger;
    private array $progressMessages = [];
    private \org\bovigo\vfs\vfsStreamDirectory $vfsRoot;
    private string $tmpDir;
    private string $downloadDir;

    protected function setUp(): void
    {
        // Set up virtual filesystem
        $this->vfsRoot = vfsStream::setup('root');
        $this->tmpDir = vfsStream::url('root/tmp');
        $this->downloadDir = vfsStream::url('root/downloads');
        mkdir($this->tmpDir);
        mkdir($this->downloadDir);

        $this->filesystem = $this->createMock(Filesystem::class);
        $this->logger = $this->createMock(Logger::class);

        $this->progressMessages = [];
    }

    /**
     * Test successful download of multiple files
     */
    public function testDownloadManySuccessful(): void
    {
        // Mock filesystem to simulate file operations
        $this->filesystem->method('exists')->willReturn(false);
        $this->filesystem->expects($this->exactly(2))->method('touch');
        $this->filesystem->expects($this->exactly(2))->method('rename');

        // Create mock responses for successful downloads
        $mockBody1 = $this->createMock(StreamInterface::class);
        $mockBody1->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $mockBody1->method('read')->willReturn('test content');

        $mockBody2 = $this->createMock(StreamInterface::class);
        $mockBody2->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $mockBody2->method('read')->willReturn('test content 2');

        $response1 = $this->createMock(ResponseInterface::class);
        $response1->method('getStatusCode')->willReturn(200);
        $response1->method('getBody')->willReturn($mockBody1);
        $response1->method('getHeaderLine')->with('Content-Length')->willReturn('12');

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('getStatusCode')->willReturn(200);
        $response2->method('getBody')->willReturn($mockBody2);
        $response2->method('getHeaderLine')->with('Content-Length')->willReturn('14');

        // Create promises that will be returned by requestAsync
        $promise1 = Create::promiseFor($response1);
        $promise2 = Create::promiseFor($response2);

        // Mock the HTTP client to return the promises
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('requestAsync')
            ->willReturnOnConsecutiveCalls($promise1, $promise2);

        // Create a new DownloadManager instance with the mock client
        $downloadManager = new DownloadManager(
            $clientMock,
            $this->filesystem,
            $this->logger,
            $this->tmpDir,
            $this->downloadDir,
            vfsStream::url('root/logs'),
            3, // maxRetries
            2, // concurrency
            1000, // maxDelaysMs
            100 // baseDelaysMs
        );

        // Define progress callback
        $progress = function ($message) {
            $this->progressMessages[] = $message;
        };

        // Execute the method under test
        $downloadManager->downloadMany(['http://example.com/file1.txt', 'http://example.com/file2.txt'], $progress);

        // Assert progress messages were received
        $this->assertCount(2, $this->progressMessages);
        $this->assertStringContainsString('Completed: http://example.com/file1.txt', $this->progressMessages[0]);
        $this->assertStringContainsString('Completed: http://example.com/file2.txt', $this->progressMessages[1]);
    }

    /**
     * Test download with network interruption
     */
    public function testDownloadManyWithNetworkInterruption(): void
    {
        // Create a file in the tmp directory to simulate a partial download
        $tmpFilePath = $this->tmpDir . '/file.txt';
        file_put_contents($tmpFilePath, 'partial');

        // Mock filesystem behavior
        $this->filesystem->method('exists')->willReturn(false);
        $this->filesystem->method('touch')->willReturnCallback(function ($path) {
            // Do nothing, file already exists
        });
        $this->filesystem->method('rename')->willReturnCallback(function ($from, $to) {
            rename($from, $to);
        });

        // Create mock responses for resumed download
        $mockBody = $this->createMock(StreamInterface::class);
        $mockBody->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $mockBody->method('read')->willReturn('_completed');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(206); // Partial content
        $response->method('getBody')->willReturn($mockBody);
        $response->method('getHeaderLine')
            ->willReturnCallback(function ($header) {
                if ($header === 'Content-Range') {
                    return 'bytes 7-16/16'; // 7 is the length of 'partial'
                }
                return '';
            });

        // Create a sequence of promises:
        // 1. First request fails with network error
        // 2. Second request succeeds with partial content
        $networkErrorPromise = new Promise(function () use (&$networkErrorPromise) {
            $networkErrorPromise->reject(new RequestException(
                'Network error',
                new Request('GET', 'http://example.com/file.txt')
            ));
        });

        $successPromise = Create::promiseFor($response);

        // Mock the HTTP client to return the promises in sequence
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('requestAsync')
            ->willReturnOnConsecutiveCalls($networkErrorPromise, $successPromise);

        // Create a new DownloadManager instance with the mock client
        $downloadManager = new DownloadManager(
            $clientMock,
            $this->filesystem,
            $this->logger,
            $this->tmpDir,
            $this->downloadDir,
            vfsStream::url('root/logs'),
            3, // maxRetries
            2, // concurrency
            1000, // maxDelaysMs
            100 // baseDelaysMs
        );

        // Define progress callback
        $progress = function ($message) {
            $this->progressMessages[] = $message;
        };

        // Execute the method under test
        $downloadManager->downloadMany(['http://example.com/file.txt'], $progress);

        // Assert progress messages were received
        $this->assertCount(1, $this->progressMessages);
        $this->assertStringContainsString('Completed: http://example.com/file.txt', $this->progressMessages[0]);

        // Verify the file was moved to the download directory
        $this->assertFileExists($this->downloadDir . '/file.txt');
    }

    /**
     * Test download with complete failure (exhausted retries)
     */
    public function testDownloadManyWithCompleteFailure(): void
    {
        // Mock filesystem behavior
        $this->filesystem->method('exists')->willReturn(false);
        $this->filesystem->expects($this->once())->method('touch');
        $this->filesystem->expects($this->never())->method('rename');

        // Create a promise that will throw an exception for all retries
        $exception = new RequestException('Network error', new Request('GET', 'http://example.com/file.txt'));

        // Create a mock client that always rejects with the same exception
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('requestAsync')
            ->willReturnCallback(function () use ($exception) {
                $promise = new Promise();
                $promise->reject($exception);
                return $promise;
            });

        // Create a new DownloadManager instance with the mock client
        $downloadManager = new DownloadManager(
            $clientMock,
            $this->filesystem,
            $this->logger,
            $this->tmpDir,
            $this->downloadDir,
            vfsStream::url('root/logs'),
            3, // maxRetries
            2, // concurrency
            1000, // maxDelaysMs
            100 // baseDelaysMs
        );

        // Define progress callback
        $progress = function ($message) {
            $this->progressMessages[] = $message;
        };

        // Execute the method under test
        $downloadManager->downloadMany(['http://example.com/file.txt'], $progress);

        // Assert progress messages were received
        $this->assertCount(1, $this->progressMessages);
        $this->assertStringContainsString('Failed: http://example.com/file.txt', $this->progressMessages[0]);
    }
}
