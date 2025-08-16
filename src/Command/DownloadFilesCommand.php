<?php

namespace App\Command;

use App\Service\DownloadManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:download', description: 'Download files')]
class DownloadFilesCommand extends Command
{
    public function __construct(
        private readonly DownloadManager $downloadManager,
        private readonly string $dirTmp,
        private readonly string $dirDownload,
    ) {
        parent::__construct();
    }

    protected function configure(): void {}

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $helper = new QuestionHelper();

        if (!is_dir($this->dirTmp) && !mkdir($this->dirTmp, 0777, true) && !is_dir($this->dirTmp)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->dirTmp));
        }

        if (!is_dir($this->dirDownload) && !mkdir($this->dirDownload, 0777, true) && !is_dir($this->dirDownload)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->dirDownload));
        }

        $choices[] = 'https://storage.googleapis.com/public_test_access_ae/output_60sec.mp4';
        $choices[] = 'https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4';
        $choices[] = 'https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4';
        $choices[] = 'https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4';
        $choices[] = 'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4';

        $question = new ChoiceQuestion(
            'What do you want to download? (comma-separate indexes, e.g. "0,2")',
            $choices,
        );
        $question->setMultiselect(true);

        /** @var string[] $selected */
        $selected = $helper->ask($input, $output, $question);

        $this->downloadManager->downloadMany($selected, function (string $msg) use ($io) {
            $io->writeln($msg);
        });

        $io->success('Done!');

        return Command::SUCCESS;
    }
}
