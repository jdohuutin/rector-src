<?php

declare(strict_types=1);

namespace Rector\Core\Application;

use Rector\Core\Application\FileDecorator\FileDiffFileDecorator;
use Rector\Core\Application\FileSystem\RemovedAndAddedFilesProcessor;
use Rector\Core\Configuration\Option;
use Rector\Core\Contract\Processor\FileProcessorInterface;
use Rector\Core\ValueObject\Application\File;
use Rector\Core\ValueObject\Configuration;
use Rector\Core\ValueObject\Error\SystemError;
use Rector\Core\ValueObject\Reporting\FileDiff;
use Rector\FileFormatter\FileFormatter;
use Rector\Parallel\Application\ParallelFileProcessor;
use Rector\Parallel\ValueObject\Bridge;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symplify\EasyParallel\CpuCoreCountProvider;
use Symplify\EasyParallel\Exception\ParallelShouldNotHappenException;
use Symplify\EasyParallel\FileSystem\FilePathNormalizer;
use Symplify\EasyParallel\ScheduleFactory;
use Symplify\PackageBuilder\Parameter\ParameterProvider;
use Symplify\PackageBuilder\Yaml\ParametersMerger;
use Symplify\SmartFileSystem\SmartFileSystem;

final class ApplicationFileProcessor
{
    /**
     * @var string
     */
    private const ARGV = 'argv';

    /**
     * @var SystemError[]
     */
    private array $systemErrors = [];

    /**
     * @param FileProcessorInterface[] $fileProcessors
     */
    public function __construct(
        private readonly SmartFileSystem $smartFileSystem,
        private readonly FileDiffFileDecorator $fileDiffFileDecorator,
        private readonly FileFormatter $fileFormatter,
        private readonly RemovedAndAddedFilesProcessor $removedAndAddedFilesProcessor,
        private readonly SymfonyStyle $symfonyStyle,
        private readonly ParametersMerger $parametersMerger,
        private readonly ParallelFileProcessor $parallelFileProcessor,
        private readonly ParameterProvider $parameterProvider,
        private readonly ScheduleFactory $scheduleFactory,
        private readonly FilePathNormalizer $filePathNormalizer,
        private readonly CpuCoreCountProvider $cpuCoreCountProvider,
        private readonly array $fileProcessors = []
    ) {
    }

    /**
     * @param File[] $files
     * @return array{system_errors: SystemError[], file_diffs: FileDiff[]}
     */
    public function run(array $files, Configuration $configuration, InputInterface $input): array
    {
        // no files found
        if ($files === []) {
            return [
                Bridge::SYSTEM_ERRORS => [],
                Bridge::FILE_DIFFS => [],
            ];
        }

        $this->configureCustomErrorHandler();

        if ($configuration->isParallel()) {
            $systemErrorsAndFileDiffs = $this->runParallel($files, $configuration, $input);

        // @todo
        } else {
            $systemErrorsAndFileDiffs = $this->processFiles($files, $configuration);
            $this->fileFormatter->format($files);

            $this->fileDiffFileDecorator->decorate($files);
            $this->printFiles($files, $configuration);
        }

        $systemErrorsAndFileDiffs[Bridge::SYSTEM_ERRORS] = array_merge(
            $systemErrorsAndFileDiffs[Bridge::SYSTEM_ERRORS],
            $this->systemErrors
        );

        $this->restoreErrorHandler();

        return $systemErrorsAndFileDiffs;
    }

    /**
     * @param File[] $files
     * @return array{system_errors: SystemError[], file_diffs: FileDiff[]}
     */
    private function processFiles(array $files, Configuration $configuration): array
    {
        if ($configuration->shouldShowProgressBar()) {
            $fileCount = count($files);
            $this->symfonyStyle->progressStart($fileCount);
        }

        $systemErrorsAndFileDiffs = [
            Bridge::SYSTEM_ERRORS => [],
            Bridge::FILE_DIFFS => [],
        ];

        foreach ($files as $file) {
            foreach ($this->fileProcessors as $fileProcessor) {
                if (! $fileProcessor->supports($file, $configuration)) {
                    continue;
                }

                $result = $fileProcessor->process($file, $configuration);

                if (is_array($result)) {
                    $systemErrorsAndFileDiffs = $this->parametersMerger->merge($systemErrorsAndFileDiffs, $result);
                }
            }

            // progress bar +1
            if ($configuration->shouldShowProgressBar()) {
                $this->symfonyStyle->progressAdvance();
            }
        }

        $this->removedAndAddedFilesProcessor->run($configuration);

        return $systemErrorsAndFileDiffs;
    }

    /**
     * @param File[] $files
     */
    private function printFiles(array $files, Configuration $configuration): void
    {
        if ($configuration->isDryRun()) {
            return;
        }

        foreach ($files as $file) {
            if (! $file->hasChanged()) {
                continue;
            }

            $this->printFile($file);
        }
    }

    private function printFile(File $file): void
    {
        $smartFileInfo = $file->getSmartFileInfo();

        $this->smartFileSystem->dumpFile($smartFileInfo->getPathname(), $file->getFileContent());
        $this->smartFileSystem->chmod($smartFileInfo->getRealPath(), $smartFileInfo->getPerms());
    }

    /**
     * Inspired by @see https://github.com/phpstan/phpstan-src/blob/89af4e7db257750cdee5d4259ad312941b6b25e8/src/Analyser/Analyser.php#L134
     */
    private function configureCustomErrorHandler(): void
    {
        $errorHandlerCallback = function (int $code, string $message, string $file, int $line): bool {
            if ((error_reporting() & $code) === 0) {
                // silence @ operator
                return true;
            }

            // not relevant for us
            if (in_array($code, [E_DEPRECATED, E_WARNING], true)) {
                return true;
            }

            $this->systemErrors[] = new SystemError($message, $file, $line);

            return true;
        };

        set_error_handler($errorHandlerCallback);
    }

    private function restoreErrorHandler(): void
    {
        restore_error_handler();
    }

    /**
     * @param File[] $files
     * @return array{system_errors: SystemError[], file_diffs: FileDiff[]}
     */
    private function runParallel(array $files, Configuration $configuration, InputInterface $input): array
    {
        $fileInfos = [];
        foreach ($files as $file) {
            $fileInfos[] = $file->getSmartFileInfo();
        }

        // must be a string, otherwise the serialization returns empty arrays
        $filePaths = $this->filePathNormalizer->resolveFilePathsFromFileInfos($fileInfos);

        $schedule = $this->scheduleFactory->create(
            $this->cpuCoreCountProvider->provide(),
            $this->parameterProvider->provideIntParameter(Option::PARALLEL_JOB_SIZE),
            $this->parameterProvider->provideIntParameter(Option::PARALLEL_MAX_NUMBER_OF_PROCESSES),
            $filePaths
        );

        // for progress bar
        $isProgressBarStarted = false;

        $postFileCallback = function (int $stepCount) use (
            &$isProgressBarStarted,
            $filePaths,
            $configuration
        ): void {
            if (! $configuration->shouldShowProgressBar()) {
                return;
            }

            if (! $isProgressBarStarted) {
                $fileCount = count($filePaths);
                $this->symfonyStyle->progressStart($fileCount);
                $isProgressBarStarted = true;
            }

            $this->symfonyStyle->progressAdvance($stepCount);
            // running in parallel here → nothing else to do
        };

        $mainScript = $this->resolveCalledRectorBinary();
        if ($mainScript === null) {
            throw new ParallelShouldNotHappenException('[parallel] Main script was not found');
        }

        // mimics see https://github.com/phpstan/phpstan-src/commit/9124c66dcc55a222e21b1717ba5f60771f7dda92#diff-387b8f04e0db7a06678eb52ce0c0d0aff73e0d7d8fc5df834d0a5fbec198e5daR139
        return $this->parallelFileProcessor->check(
            $schedule,
            $mainScript,
            $postFileCallback,
            $configuration->getConfig(),
            $input
        );
    }

    /**
     * Path to called "ecs" binary file, e.g. "vendor/bin/ecs" returns "vendor/bin/ecs" This is needed to re-call the
     * ecs binary in sub-process in the same location.
     */
    private function resolveCalledRectorBinary(): ?string
    {
        if (! isset($_SERVER[self::ARGV][0])) {
            return null;
        }

        $potentialEcsBinaryPath = $_SERVER[self::ARGV][0];
        if (! file_exists($potentialEcsBinaryPath)) {
            return null;
        }

        return $potentialEcsBinaryPath;
    }
}
