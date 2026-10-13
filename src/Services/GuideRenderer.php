<?php

namespace RustixDev\Guides\Services;

use Closure;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use RustixDev\Guides\Support\Placeholders;
use RustixDev\Guides\Sync\ArchiveExtractor;

/**
 * Turns a guide into HTML that is safe to show inside the panel. Raw HTML is escaped
 * and script-like links (javascript:, data: and so on) are dropped by CommonMark itself.
 * On top of that: links open in a new tab, links to another guide of the folder stay
 * in the panel, images may only come from the folder's images/ (served by the panel),
 * and any other relative link is shown as plain text.
 */
final class GuideRenderer
{
    /**
     * @param  array<string, string>  $placeholders
     * @param  Closure(string): ?string  $imageUrl  file name under images/ => URL, or null when it does not exist
     * @param  list<string>  $guides  slugs of the folder's guides, for links between them
     */
    public function render(string $markdown, array $placeholders, Closure $imageUrl, array $guides = []): string
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'renderer' => ['soft_break' => "\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event) use ($imageUrl, $guides): void {
            $nodes = [];
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof Link || $node instanceof Image) {
                    $nodes[] = $node;
                }
            }
            foreach ($nodes as $node) {
                $node instanceof Image ? $this->image($node, $imageUrl) : $this->link($node, $guides);
            }
        });

        $html = (string) (new MarkdownConverter($environment))->convert(Placeholders::fill($markdown, $placeholders));

        // Wide tables scroll on their own instead of stretching the page on phones.
        return str_replace(['<table>', '</table>'], ['<div class="rxgd-table"><table>', '</table></div>'], $html);
    }

    /** @param list<string> $guides */
    private function link(Link $link, array $guides): void
    {
        $url = trim($link->getUrl());
        if (preg_match('#^\s*(?:javascript|vbscript|file|data):#i', $url) === 1) {
            $this->unwrap($link);

            return;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1 || str_starts_with($url, '//')) {
            $link->data->set('attributes/target', '_blank');
            $link->data->set('attributes/rel', 'noopener noreferrer');

            return;
        }
        if (str_starts_with($url, '#')) {
            return;
        }
        if (preg_match('#^(?:\./)?('.ArchiveExtractor::SLUG.')\.md(?:\#.*)?$#D', $url, $m) === 1 && in_array($m[1], $guides, true)) {
            $link->setUrl('#'.$m[1]);
            $link->data->set('attributes/data-guide', $m[1]);

            return;
        }

        $this->unwrap($link);
    }

    /** @param Closure(string): ?string $imageUrl */
    private function image(Image $image, Closure $imageUrl): void
    {
        $url = trim($image->getUrl());
        if (preg_match('#^(?:\./)?images/('.ArchiveExtractor::IMAGE.')$#Di', $url, $m) === 1 && ($served = $imageUrl($m[1])) !== null) {
            $image->setUrl($served);
            $image->data->set('attributes/loading', 'lazy');

            return;
        }

        // Images from elsewhere are not loaded (that would tell another site who reads
        // the guide); an absolute one becomes a link, anything else just its text.
        if (preg_match('#^https?://#i', $url) === 1) {
            $link = new Link($url, null, $image->getTitle());
            $link->data->set('attributes/target', '_blank');
            $link->data->set('attributes/rel', 'noopener noreferrer');
            $this->moveChildren($image, $link);
            if ($link->firstChild() === null) {
                $link->appendChild(new Text('Image'));
            }
            $image->replaceWith($link);

            return;
        }

        $this->unwrap($image);
    }

    private function unwrap(Node $node): void
    {
        foreach ($node->children() as $child) {
            $node->insertBefore($child);
        }
        $node->detach();
    }

    private function moveChildren(Node $from, Node $to): void
    {
        foreach ($from->children() as $child) {
            $to->appendChild($child);
        }
    }
}
