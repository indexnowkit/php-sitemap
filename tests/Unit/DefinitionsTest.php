<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap\Tests\Unit;

use IndexNowKit\Sitemap\Console\Definitions;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

final class DefinitionsTest extends TestCase
{
    #[TestDox('sitemap() covers every constructor parameter of SitemapOptions, in order, and prints the option name it was given')]
    public function testCoversSitemapOptions(): void
    {
        $definition = Definitions::sitemap('indexnow.sitemap.url');
        $inputs = array_map(static fn($a): string => $a->name, $definition->arguments);
        foreach ($definition->options as $option) {
            $inputs[] = $option->property();
        }
        $parameters = array_map(static fn(ReflectionParameter $p): string => $p->getName(), (new ReflectionClass(SitemapOptions::class))->getConstructor()?->getParameters() ?? []);
        self::assertSame($parameters, $inputs);
        self::assertStringContainsString('(default: indexnow.sitemap.url from the config', $definition->argument('sitemap')->description);
        self::assertFalse($definition->argument('sitemap')->required);
        self::assertSame(['f' => 'force'], $definition->yiiAliases());
        self::assertStringContainsString('{sitemap? : Sitemap URL or local file', $definition->laravelSignature('indexnow:sitemap'));
    }
}
