<?php

namespace App\Tests\Unit\Release;

use App\Release\ReleaseContentSettings;
use PHPUnit\Framework\TestCase;

class ReleaseContentSettingsTest extends TestCase
{
    public function testParseRepositories(): void
    {
        $repositories = ReleaseContentSettings::parseRepositories(implode("\n", [
            'mon-groupe/portail-front',
            'https://gitlab.entreprise.fr/mon-groupe/sous-groupe/portail-api.git | v2.3.0',
            'git@gitlab.entreprise.fr:mon-groupe/batch.git | master | release',
            'https://gitlab.entreprise.fr/mon-groupe/web/-/tree/develop',
            '1234',
            '  ',
            'pas un chemin !',
        ]));

        self::assertSame([
            ['path' => 'mon-groupe/portail-front', 'reference' => null, 'branch' => null],
            ['path' => 'mon-groupe/sous-groupe/portail-api', 'reference' => 'v2.3.0', 'branch' => null],
            ['path' => 'mon-groupe/batch', 'reference' => 'master', 'branch' => 'release'],
            ['path' => 'mon-groupe/web', 'reference' => null, 'branch' => null],
            ['path' => '1234', 'reference' => null, 'branch' => null],
        ], $repositories);
    }

    public function testFormatRepositoriesRoundTrip(): void
    {
        $text = "groupe/a\ngroupe/b | v1\ngroupe/c |  | release";
        $repositories = ReleaseContentSettings::parseRepositories($text);

        self::assertSame("groupe/a\ngroupe/b | v1\ngroupe/c |  | release", ReleaseContentSettings::formatRepositories($repositories));
        self::assertSame($repositories, ReleaseContentSettings::parseRepositories(ReleaseContentSettings::formatRepositories($repositories)));
    }

    public function testParseProjectKeysAndList(): void
    {
        self::assertSame(['APP', 'WS'], ReleaseContentSettings::parseProjectKeys('app, ws ; APP 1X'));
        self::assertSame(['Prêt pour MEP', 'Terminé'], ReleaseContentSettings::parseList("Prêt pour MEP\nTerminé, Terminé"));
    }
}
