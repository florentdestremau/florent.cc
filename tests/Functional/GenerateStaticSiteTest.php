<?php

namespace App\Tests\Functional;

use App\Content\ContentRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class GenerateStaticSiteTest extends KernelTestCase
{
    public function testGeneratesEveryPage(): void
    {
        $kernel = self::bootKernel();
        $output = $kernel->getCacheDir().'/static-site';
        (new Filesystem())->remove($output);

        $command = new CommandTester((new Application($kernel))->find('static-site:generate'));
        $command->execute(['--allow-debug' => true]);

        $command->assertCommandIsSuccessful();
        self::assertFileExists("$output/index.html");
        self::assertFileExists("$output/about/index.html");
        self::assertFileExists("$output/sitemap.xml");

        foreach (self::getContainer()->get(ContentRepository::class)->publishedPosts() as $post) {
            $file = "$output/posts/$post->year/$post->month/$post->slug/index.html";
            self::assertFileExists($file);
            self::assertStringContainsString(
                "<link rel=\"canonical\" href=\"https://florent.cc/posts/$post->year/$post->month/$post->slug/\">",
                file_get_contents($file),
            );
        }
    }
}
