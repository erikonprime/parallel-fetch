# Parallel Fetch

A PHP application for downloading multiple files in parallel with resume capability. This tool allows you to efficiently download multiple files simultaneously with automatic retries.

## Features

- **Parallel Downloads**: Download multiple files concurrently with configurable concurrency level
- **Automatic Retries**: Configurable retry mechanism for failed downloads
- **Resume Capability**: Automatically resume interrupted downloads

## Requirements

- PHP 8.2 or higher
- Composer
- Docker (for containerized setup)

## Installation

### Using Composer

```bash
# Clone the repository
git clone https://github.com/example/parallel-fetch.git
cd parallel-fetch

# Install dependencies
composer install
```

### Using Docker

```bash
# Build the Docker image
docker compose build --no-cache

# Start the containers
docker compose up -d --force-recreate
```

## Usage

### Command Line Interface

The application provides a command-line interface for downloading files:

```bash
# Run the download command
php bin/console app:download
```

This will present you with a list of predefined files to download. You can select multiple files by providing comma-separated indices.

### Predefined Files

The application comes with the following predefined files for download:
- Video file (60 seconds): https://storage.googleapis.com/public_test_access_ae/output_60sec.mp4
- Video file (50 seconds): https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4
- Video file (40 seconds): https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4
- Video file (30 seconds): https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4
- Video file (20 seconds): https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4

### Adding Custom Files

To add custom files for download, you can modify the `DownloadFilesCommand.php` file:

1. Open `src/Command/DownloadFilesCommand.php`
2. Add your custom URLs to the `$choices` array

```php
// Example of adding a custom file
$choices[] = 'https://example.com/path/to/your/file.zip';
```

## Configuration

The application can be configured through environment variables or by modifying the service configuration in `config/services.yaml`.

### Environment Variables

- `DIR_DOWNLOAD`: Directory where downloaded files will be stored
- `DIR_TMP`: Directory for temporary files during download
- `DIR_LOG`: Directory for log files
- `MAX_RETRIES`: Maximum number of retry attempts for failed downloads
- `CONCURRENCY`: Number of parallel downloads
- `MAX_DELAYS_MS`: Maximum delay in milliseconds between retry attempts
- `BASE_DELAYS_MS`: Base delay in milliseconds for exponential backoff

### Service Configuration

The `DownloadManager` service is configured in `config/services.yaml` using parameters:

```yaml
parameters:
    dir_tmp: '%kernel.project_dir%/%env(DIR_TMP)%'
    dir_download: '%kernel.project_dir%/%env(DIR_DOWNLOAD)%'
    dir_log: '%kernel.project_dir%/%env(DIR_LOG)%'
    max_retries: '%env(MAX_RETRIES)%'
    concurrency: '%env(CONCURRENCY)%'
    max_delays_ms: '%env(MAX_DELAYS_MS)%'
    base_delays_ms: '%env(BASE_DELAYS_MS)%'

services:
    # ...
    App\Service\DownloadManager:
        arguments:
            $dirTmp: '%dir_tmp%'
            $dirDownload: '%dir_download%'
            $dirLog: '%dir_log%'
            $maxRetries: '%max_retries%'
            $concurrency: '%concurrency%'
            $maxDelaysMs: '%max_delays_ms%'
            $baseDelaysMs: '%base_delays_ms%'
```

## Project Structure

- `src/Service/DownloadManager.php`: Core service for downloading files in parallel
- `src/Command/DownloadFilesCommand.php`: Symfony Console command for CLI interface
- `tests/Service/DownloadManagerTest.php`: Tests for the DownloadManager service

## Development

### Running Tests

```bash
# Run PHPUnit tests
php bin/phpunit
```

### Resources

- [Guzzle HTTP Client Documentation](https://docs.guzzlephp.org/en/stable/quickstart.html)
- [Symfony 7.3 Documentation](https://symfony.com/doc/7.3/index.html)
