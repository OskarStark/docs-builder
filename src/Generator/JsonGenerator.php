<?php

declare(strict_types=1);

/*
 * This file is part of the Docs Builder package.
 * (c) Ryan Weaver <ryan@symfonycasts.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SymfonyDocsBuilder\Generator;

use Doctrine\RST\Environment;
use Doctrine\RST\Meta\MetaEntry;
use Doctrine\RST\Meta\Metas;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use SymfonyDocsBuilder\BuildConfig;
use function Symfony\Component\String\u;

class JsonGenerator
{
    private $metas;

    private $buildConfig;

    /** @var SymfonyStyle|null */
    private $output;

    public function __construct(Metas $metas, BuildConfig $buildConfig)
    {
        $this->metas = $metas;
        $this->buildConfig = $buildConfig;
    }

    /**
     * Returns an array of each JSON file string, keyed by the input filename
     *
     * @return string[]
     */
    public function generateJson(): array
    {
        $fs = new Filesystem();

        $progressBar = new ProgressBar($this->output ?: new NullOutput());
        $progressBar->setMaxSteps(\count($this->metas->getAll()));

        $fJsonFiles = [];
        foreach ($this->metas->getAll() as $filename => $metaEntry) {
            $parserFilename = $filename;
            $jsonFilename = $this->buildConfig->getOutputDir().'/'.$filename.'.fjson';

            $crawler = new Crawler(file_get_contents($this->buildConfig->getOutputDir().'/'.$filename.'.html'));

            $data = [
                'title' => $metaEntry->getTitle(),
                'current_page_name' => $parserFilename,
                'toc' => $this->generateToc($metaEntry, current($metaEntry->getTitles())[1]),
                'next' => $this->guessNext($parserFilename),
                'prev' => $this->guessPrev($parserFilename),
                'rellinks' => [
                    $this->guessNext($parserFilename),
                    $this->guessPrev($parserFilename),
                ],
                'body' => $crawler->filter('body')->html(),
            ];

            $fs->dumpFile(
                $jsonFilename,
                json_encode($data, JSON_PRETTY_PRINT)
            );
            $fJsonFiles[$filename] = $data;

            $progressBar->advance();
        }

        $progressBar->finish();

        return $fJsonFiles;
    }

    public function setOutput(SymfonyStyle $output)
    {
        $this->output = $output;
    }

    private function generateToc(MetaEntry $metaEntry, ?array $titles): array
    {
        if (null === $titles) {
            return [];
        }

        $tocTree = [];

        foreach ($titles as $title) {
            $tocTree[] = [
                'url' => sprintf('%s#%s', $metaEntry->getUrl(), Environment::slugify($title[0])),
                'page' => u($metaEntry->getUrl())->beforeLast('.html'),
                'fragment' => Environment::slugify($title[0]),
                'title' => $title[0],
                'children' => $this->generateToc($metaEntry, $title[1]),
            ];
        }

        return $tocTree;
    }

    private function guessNext(string $parserFilename): ?array
    {
        $meta = $this->getMetaEntry($parserFilename, true);

        $parentFile = $meta->getParent();

        // if current file is an index, next is the first chapter
        if ('index' === $parentFile && 1 === \count($tocs = $meta->getTocs()) && \count($tocs[0]) > 0) {
            $firstChapterMeta = $this->getMetaEntry($tocs[0][0]);

            if (null === $firstChapterMeta) {
                return null;
            }

            return [
                'title' => $firstChapterMeta->getTitle(),
                'link' => $firstChapterMeta->getUrl(),
            ];
        }

        [$toc, $indexCurrentFile] = $this->getNextPrevInformation($parserFilename);

        if (!isset($toc[$indexCurrentFile + 1])) {
            return null;
        }

        $nextFileName = $toc[$indexCurrentFile + 1];

        $nextMeta = $this->getMetaEntry($nextFileName);

        if (null === $nextMeta) {
            return null;
        }

        return [
            'title' => $nextMeta->getTitle(),
            'link' => $nextMeta->getUrl(),
        ];
    }

    private function guessPrev(string $parserFilename): ?array
    {
        $meta = $this->getMetaEntry($parserFilename, true);
        $parentFile = $meta->getParent();

        [$toc, $indexCurrentFile] = $this->getNextPrevInformation($parserFilename);

        // if current file is the first one of the chapter, prev is the direct parent
        if (0 === $indexCurrentFile) {
            $parentMeta = $this->getMetaEntry($parentFile);

            if (null === $parentMeta) {
                return null;
            }

            return [
                'title' => $parentMeta->getTitle(),
                'link' => $parentMeta->getUrl(),
            ];
        }

        if (!isset($toc[$indexCurrentFile - 1])) {
            return null;
        }

        $prevFileName = $toc[$indexCurrentFile - 1];

        $prevMeta = $this->getMetaEntry($prevFileName);

        if (null === $prevMeta) {
            return null;
        }

        return [
            'title' => $prevMeta->getTitle(),
            'link' => $prevMeta->getUrl(),
        ];
    }

    private function getNextPrevInformation(string $parserFilename): ?array
    {
        $meta = $this->getMetaEntry($parserFilename, true);
        $parentFile = $meta->getParent();

        if (!$parentFile) {
            return [null, null];
        }

        $metaParent = $this->getMetaEntry($parentFile);

        if (null === $metaParent || !$metaParent->getTocs() || 1 !== \count($metaParent->getTocs())) {
            return [null, null];
        }

        $toc = current($metaParent->getTocs());

        if (\count($toc) < 2 || !isset(array_flip($toc)[$parserFilename])) {
            return [null, null];
        }

        $indexCurrentFile = array_flip($toc)[$parserFilename];

        return [$toc, $indexCurrentFile];
    }

    private function getMetaEntry(string $parserFilename, bool $throwOnMissing = false): ?MetaEntry
    {
        $metaEntry = $this->metas->get($parserFilename);

        // this is possible if there are invalid references
        if (null === $metaEntry) {
            $message = sprintf('Could not find MetaEntry for file "%s"', $parserFilename);

            if ($throwOnMissing) {
                throw new \Exception($message);
            }

            if ($this->output) {
                $this->output->note($message);
            }
        }

        return $metaEntry;
    }
}
