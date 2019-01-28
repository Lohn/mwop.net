<?php
/**
 * @license http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @copyright Copyright (c) Matthew Weier O'Phinney
 */

declare(strict_types=1);

namespace Mwop\Blog\Console;

use Mwop\Blog\BlogPost;
use Mwop\Blog\Mapper\MapperInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Parser as YamlParser;
use Traversable;
use Zend\Diactoros\Uri;
use Zend\Expressive\Helper\ServerUrlHelper;
use Zend\Expressive\Router\RouterInterface;
use Zend\Expressive\Template\TemplateRendererInterface;
use Zend\Feed\Writer\Feed as FeedWriter;

use function file_exists;
use function file_put_contents;
use function iterator_to_array;
use function method_exists;
use function sprintf;
use function str_replace;

class FeedGenerator extends Command
{
    use RoutesTrait;

    private $authors = [];

    private $authorsPath;

    private $defaultAuthor = [
        'name'  => 'Matthew Weier O\'Phinney',
        'email' => 'me@mwop.net',
        'uri'   => 'https://mwop.net',
    ];

    private $io;

    private $mapper;

    private $renderer;

    private $router;

    public function __construct(
        MapperInterface $mapper,
        RouterInterface $router,
        TemplateRendererInterface $renderer,
        ServerUrlHelper $serverUrlHelper,
        string $authorsPath
    ) {
        $this->mapper      = $mapper;
        $this->router      = $this->seedRoutes($router);
        $this->renderer    = $renderer;
        $this->authorsPath = $authorsPath;

        $serverUrlHelper->setUri(new Uri('https://mwop.net'));

        parent::__construct();
    }

    protected function configure() : void
    {
        $this->setName('blog:feed-generator');
        $this->setDescription('Generate blog feeds.');
        $this->setHelp('Generate feeds (RSS and Atom) for the blog, including all tags.');

        $this->addOption(
            'output-dir',
            'o',
            InputOption::VALUE_REQUIRED,
            'Directory to which to write the feeds.',
            'data/feeds'
        );

        $this->addOption(
            'base-uri',
            'b',
            InputOption::VALUE_REQUIRED,
            'Base URI for the site.',
            'https://mwop.net'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $io        = new SymfonyStyle($input, $output);
        $outputDir = $input->getOption('output-dir');
        $baseUri   = $input->getOption('base-uri');

        $io->title('Generating blog feeds');
        $io->text('<info>Generating base feeds</>');
        $io->progressStart();

        $this->generateFeeds(
            $outputDir . '/',
            $baseUri,
            'Blog entries :: phly, boy, phly',
            'blog',
            'blog.feed',
            [],
            $this->mapper->fetchAll()
        );

        $cloud = $this->mapper->fetchTagCloud();
        $tags  = array_map(function ($item) {
            return $item->getTitle();
        }, iterator_to_array($cloud->getItemList()));

        foreach ($tags as $tag) {
            if (empty($tag)) {
                continue;
            }

            $io->text('Generating feeds for tag ' . $tag);
            $io->progressStart();
            $this->generateFeeds(
                sprintf('%s/%s.', $outputDir, $tag),
                $baseUri,
                sprintf('Tag: %s :: phly, boy, phly', $tag),
                'blog.tag',
                'blog.tag.feed',
                ['tag' => $tag],
                $this->mapper->fetchAllByTag($tag)
            );
            $io->progressFinish();
        }

        return 0;
    }

    private function generateFeeds(
        string $fileBase,
        string $baseUri,
        string $title,
        string $landingRoute,
        string $feedRoute,
        array $routeOptions,
        Traversable $posts
    ) {
        foreach (['atom', 'rss'] as $type) {
            $this->generateFeed($type, $fileBase, $baseUri, $title, $landingRoute, $feedRoute, $routeOptions, $posts);
        }
    }

    private function generateFeed(
        string $type,
        string $fileBase,
        string $baseUri,
        string $title,
        string $landingRoute,
        string $feedRoute,
        array $routeOptions,
        Traversable $posts
    ) {
        $routeOptions['type'] = $type;

        $landingUri = $baseUri . $this->generateUri($landingRoute, $routeOptions);
        $feedUri    = $baseUri . $this->generateUri($feedRoute, $routeOptions);

        $feed = new FeedWriter();
        $feed->setTitle($title);
        $feed->setLink($landingUri);
        $feed->setFeedLink($feedUri, $type);

        if ($type === 'rss') {
            $feed->setDescription($title);
        }

        $latest = false;

        if (method_exists($posts, 'setCurrentPageNumber')) {
            $posts->setCurrentPageNumber(1);
        }

        foreach ($posts as $post) {
            $html   = $post->body . $post->extended;
            $author = $this->getAuthor($post->author);

            if (! $latest) {
                $latest = $post;
            }

            $entry = $feed->createEntry();
            $entry->setTitle($post->title);
            // $entry->setLink($baseUri . $this->generateUri('blog.post', ['id' => $post->id]));
            $entry->setLink($baseUri . sprintf('/blog/%s.html', $post->id));

            $entry->addAuthor($author);
            $entry->setDateModified($post->updated);
            $entry->setDateCreated($post->created);
            $entry->setContent($this->createContent($html, $post));

            $feed->addEntry($entry);
        }

        // Set feed date
        $feed->setDateModified($latest->updated);

        // Write feed to file
        $file = sprintf('%s%s.xml', $fileBase, $type);
        $file = str_replace(' ', '+', $file);
        file_put_contents($file, $feed->export($type));
    }

    /**
     * Retrieve author metadata.
     *
     * @param string $author
     * @return string[]
     */
    private function getAuthor(string $author) : array
    {
        if (isset($this->authors[$author])) {
            return $this->authors[$author];
        }

        $path = sprintf('%s/%s.yml', $this->authorsPath, $author);
        if (! file_exists($path)) {
            $this->authors[$author] = $this->defaultAuthor;
            return $this->authors[$author];
        }

        $this->authors[$author] = (new YamlParser())->parse(file_get_contents($path));
        return $this->authors[$author];
    }

    /**
     * Normalize generated URIs.
     *
     * @param string $route
     * @param array $options
     * @return string
     */
    private function generateUri(string $route, array $options) : string
    {
        $uri = $this->router->generateUri($route, $options);
        return str_replace('[/]', '', $uri);
    }

    /**
     * Create feed content.
     *
     * Renders h-entry data for the feed and appends it to the HTML markup content.
     */
    private function createContent(string $content, BlogPost $post) : string
    {
        return sprintf(
            "%s\n\n%s",
            $content,
            $this->renderer->render('blog::hcard', ['post' => $post])
        );
    }
}
