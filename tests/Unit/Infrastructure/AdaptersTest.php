<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Infrastructure;

use Phil\SpamProtectionBundle\Infrastructure\Cache\CacheUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Http\ClientKey;
use Phil\SpamProtectionBundle\Infrastructure\RateLimit\SymfonySubmissionRateLimiter;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\RacingPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(CacheUsedTokens::class)]
#[CoversClass(SymfonySubmissionRateLimiter::class)]
#[CoversClass(ClientKey::class)]
final class AdaptersTest extends TestCase
{
    public function testANonceIsClaimedOnceForItsTimeToLive(): void
    {
        $pool = new ArrayAdapter();
        $tokens = new CacheUsedTokens($pool);

        self::assertTrue($tokens->claim('abc', 60));
        self::assertFalse($tokens->claim('abc', 60));
        self::assertTrue($tokens->claim('def', 60));
        self::assertTrue($pool->getItem('phil_spam_protection.abc')->isHit());
        self::assertTrue($tokens->claim('gone', -1));
        self::assertTrue($tokens->claim('gone', -1));
    }

    /**
     * Two submissions of one token write one after the other: the one whose marker was overwritten is refused.
     */
    public function testAClaimOverwrittenBeforeItIsReadBackIsRefused(): void
    {
        self::assertFalse(new CacheUsedTokens(new RacingPool())->claim('abc', 60));
    }

    public function testAClaimLeavesItsMarkerInThePool(): void
    {
        $pool = new ArrayAdapter();
        $tokens = new CacheUsedTokens($pool);

        self::assertTrue($tokens->claim('abc', 60));
        $marker = $pool->getItem('phil_spam_protection.abc')->get();
        self::assertIsString($marker);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $marker);
    }

    public function testTheRateLimitCountsPerFormAndClient(): void
    {
        $limiter = new SymfonySubmissionRateLimiter(new RateLimiterFactory(['id' => 'forms', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 hour'], new InMemoryStorage()));

        self::assertTrue($limiter->consume('contact', 'a'));
        self::assertTrue($limiter->consume('contact', 'a'));
        self::assertFalse($limiter->consume('contact', 'a'));
        self::assertTrue($limiter->consume('contact', 'b'));
        self::assertTrue($limiter->consume('signup', 'a'));
        self::assertTrue($limiter->consume('ab', 'c'));
        self::assertTrue($limiter->consume('a', 'bc'));
        self::assertTrue($limiter->consume('a', 'bc'));
        self::assertFalse($limiter->consume('a', 'bc'));
    }

    public function testTheClientKeyIsAKeyedHashOfTheAddress(): void
    {
        self::assertNull(new ClientKey(new RequestStack(), 'secret')->current());

        $requests = new RequestStack([Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.7'])]);
        $key = new ClientKey($requests, 'secret');

        self::assertSame(hash_hmac('sha256', '203.0.113.7', 'secret'), $key->current());
        self::assertNotSame($key->current(), new ClientKey($requests, 'other')->current());

        $requests->push(new Request());

        self::assertNull($key->current());
    }

    /**
     * An IPv6 host picks any address in its `/64`: every one of them shares one key, so rotating does not escape the
     * rate limit.
     */
    public function testAnIpv6ClientIsKeyedByItsSlash64(): void
    {
        $key = static fn (string $address): ?string => new ClientKey(new RequestStack([Request::create('/', server: ['REMOTE_ADDR' => $address])]), 'secret')->current();

        self::assertSame($key('2001:db8:1:2::1'), $key('2001:db8:1:2:ffff:abcd:1234:5678'));
        self::assertNotSame($key('2001:db8:1:2::1'), $key('2001:db8:1:3::1'));
        self::assertSame(hash_hmac('sha256', '2001:db8:1:2::/64', 'secret'), $key('2001:0DB8:0001:0002:0000:0000:0000:0001'));
        self::assertSame($key('203.0.113.7'), $key('::ffff:203.0.113.7'));
        self::assertNotSame($key('203.0.113.7'), $key('203.0.113.8'));
    }

    public function testTheNetworkOfAnAddress(): void
    {
        self::assertSame('203.0.113.7', ClientKey::network('203.0.113.7'));
        self::assertSame('203.0.113.7', ClientKey::network('::ffff:203.0.113.7'));
        self::assertSame('2001:db8::/64', ClientKey::network('2001:db8::9'));
        self::assertSame('::/64', ClientKey::network('::1'));
        self::assertSame('not an address', ClientKey::network('not an address'));
    }
}
