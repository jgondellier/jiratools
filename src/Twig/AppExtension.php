<?php

namespace App\Twig;

use App\Service\ReleaseNoteService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('humanize_label', ReleaseNoteService::humanizeLabel(...)),
        ];
    }
}
