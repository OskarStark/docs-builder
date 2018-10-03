<?php

namespace SymfonyDocs\Reference;

use Doctrine\RST\Environment;
use Doctrine\RST\Reference;
use Doctrine\RST\References\ResolvedReference;
use SymfonyDocs\HtmlKernel;

class ClassReference extends Reference
{
    private const BASE__URL = 'https://api.symfony.com';

    public function getName(): string
    {
        return 'class';
    }

    public function resolve(Environment $environment, string $data): ResolvedReference
    {
        $className = str_replace('\\\\', '\\', $data);

        return new ResolvedReference(
            $className,
            sprintf('%s/%s/%s.html', self::BASE__URL, HtmlKernel::getVersion(), str_replace('\\', '/', $className)),
            [],
            [
                'class' => 'reference external',
                'title' => $className,
            ]
        );
    }
}
