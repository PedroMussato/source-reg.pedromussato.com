<?php
declare(strict_types=1);

namespace Wiki\Markdown;

final class RenderedPage
{
    /** @param list<array{level:int,id:string,text:string}> $toc */
    public function __construct(
        public readonly string $html,
        public readonly string $title,
        public readonly array $toc,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['html' => $this->html, 'title' => $this->title, 'toc' => $this->toc];
    }
}
