<?php

namespace App\Tests\LocalUpdate;

use App\LocalUpdate\PathMatcher;
use PHPUnit\Framework\TestCase;

class PathMatcherTest extends TestCase
{
    public function testPatterns(): void
    {
        $matcher = new PathMatcher(['.env.local', '.env.*.local', 'var/', 'public/uploads/', '*.log', 'config/jwt/*.pem', '\\vendor\\']);

        self::assertTrue($matcher->matches('.env.local'));
        self::assertTrue($matcher->matches('.env.prod.local'));
        self::assertFalse($matcher->matches('.env'));
        self::assertFalse($matcher->matches('.env.prod'));
        self::assertTrue($matcher->matches('var/cache/dev/x.php'));
        self::assertFalse($matcher->matches('variables.php'));
        self::assertFalse($matcher->matches('src/var/x.php'));
        self::assertTrue($matcher->matches('public/uploads/a/b.jpg'));
        self::assertTrue($matcher->matches('logs/deep/app.log'));
        self::assertTrue($matcher->matches('config/jwt/private.pem'));
        self::assertFalse($matcher->matches('config/jwt/sub/private.pem'));
        self::assertTrue($matcher->matches('vendor/autoload.php'));
        self::assertFalse($matcher->matches('src/Kernel.php'));
    }
}
