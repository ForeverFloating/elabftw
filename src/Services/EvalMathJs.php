<?php

declare(strict_types=1);

namespace Elabftw\Services;

use Elabftw\Elabftw\FsTools;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException as SymfonyProcessFailedException;
use Symfony\Component\Process\Process;

use function dirname;
use function file_put_contents;
use function unlink;

final class EvalMathJs
{
    public bool $mathJsFailed = false;

    private string $contentWithMathJs = '';

    public function __construct(private LoggerInterface $log, private string $source) {}

    public function getContent(): string
    {
        $this->contentWithMathJs = $this->runNodeApp($this->source);

        if ($this->contentWithMathJs === '') {
            return $this->source;
        }

        return $this->contentWithMathJs;
    }

    private function runNodeApp(string $content): string
    {
        $tmpFile = FsTools::getCacheFile();
        file_put_contents($tmpFile, $content);

        $appDir = dirname(__DIR__, 2) . '/src/node';

        $process = new Process(
            array(
                'node',
                $appDir . '/evalmathjs.bundle.js',
                $tmpFile,
            )
        );
        $process->run();

        unlink($tmpFile);

        if (!$process->isSuccessful()) {
            $this->log->warning('PDF generation failed during math.js processing.', array('Error', new SymfonyProcessFailedException($process)));

            $this->mathJsFailed = true;
            return '';
        }
        return $process->getOutput();
    }
}
