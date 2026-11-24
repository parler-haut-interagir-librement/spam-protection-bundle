<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Infrastructure;

use Phil\SpamProtectionBundle\Infrastructure\Cache\CacheUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Http\ClientKey;
use Phil\SpamProtectionBundle\Infrastructure\RateLimit\SymfonySubmissionRateLimiter;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\InterleavingPool;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\KeyRecordingLockStore;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\KeyRecordingPool;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\RacingPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
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
        self::assertTrue($pool->getItem($this->key('abc'))->isHit());
        self::assertFalse($tokens->claim('gone', -1), 'A claim that expires at once is not kept, so it is refused.');
    }

    /**
     * A pool that does not keep the claim — its save() answers false, or it keeps nothing — refuses the token: a claim
     * accepted without its marker would let the same token through again.
     */
    public function testAClaimThePoolDoesNotKeepIsRefused(): void
    {
        $refusing = new class extends ArrayAdapter {
            public function save(CacheItemInterface $item): bool
            {
                return false;
            }
        };

        self::assertFalse(new CacheUsedTokens($refusing)->claim('abc', 60));
        self::assertFalse(new CacheUsedTokens($refusing, new LockFactory(new InMemoryStore()))->claim('abc', 60));
        self::assertFalse(new CacheUsedTokens(new NullAdapter())->claim('abc', 60), 'A pool that keeps nothing cannot tell a token used.');
        $unsure = new class extends ArrayAdapter {
            public function save(CacheItemInterface $item): bool
            {
                parent::save($item);

                return false;
            }
        };
        self::assertFalse(new CacheUsedTokens($unsure)->claim('abc', 60), 'A write the pool does not confirm is not trusted, even if it went through.');
        self::assertTrue(new CacheUsedTokens(new ArrayAdapter())->claim('abc', 60));
    }

    /**
     * Two submissions of one token write one after the other: the one whose marker was overwritten is refused.
     */
    public function testAClaimOverwrittenBeforeItIsReadBackIsRefused(): void
    {
        self::assertFalse(new CacheUsedTokens(new RacingPool())->claim('abc', 60));
    }

    /**
     * The window `CacheUsedTokens` documents without a lock: a second submission reads, writes and reads back between
     * the first one's read and write, and both pass. Under a lock, the second one waits and is refused.
     */
    public function testWithoutALockTwoInterleavedClaimsBothPass(): void
    {
        $second = null;
        $pool = new InterleavingPool(static function (InterleavingPool $pool) use (&$second): void {
            $second = new CacheUsedTokens($pool)->claim('abc', 60);
        });

        self::assertTrue(new CacheUsedTokens($pool)->claim('abc', 60));
        self::assertTrue($second, 'The window closed on its own: the documentation of CacheUsedTokens is out of date.');
    }

    public function testUnderALockAClaimReadsAndWritesWhileHoldingIt(): void
    {
        $events = [];
        $pool = new InterleavingPool(static function () use (&$events): void {
            $events[] = 'read';
        });
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::exactly(2))->method('acquire')->with(true)->willReturnCallback(static function () use (&$events): bool {
            $events[] = 'acquire';

            return true;
        });
        $lock->expects(self::exactly(2))->method('release')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'release';
        });
        $locks = $this->createMock(LockFactory::class);
        $locks->expects(self::exactly(2))->method('createLock')->with($this->key('abc'), 10.0)->willReturn($lock);
        $tokens = new CacheUsedTokens($pool, $locks);

        self::assertTrue($tokens->claim('abc', 60));
        self::assertFalse($tokens->claim('abc', 60));
        self::assertSame(['acquire', 'read', 'release', 'acquire', 'release'], $events);
        $marker = $pool->pool->getItem($this->key('abc'))->get();
        self::assertIsString($marker);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $marker);
    }

    public function testUnderALockANonceIsClaimedOnceForItsTimeToLive(): void
    {
        $pool = new ArrayAdapter();
        $tokens = new CacheUsedTokens($pool, new LockFactory(new InMemoryStore()));

        self::assertTrue($tokens->claim('abc', 60));
        self::assertFalse($tokens->claim('abc', 60));
        self::assertTrue($tokens->claim('def', 60));
        self::assertTrue($tokens->claim('gone', -1));
        self::assertTrue($tokens->claim('gone', -1));
    }

    public function testAClaimLeavesItsMarkerInThePool(): void
    {
        $pool = new ArrayAdapter();
        $tokens = new CacheUsedTokens($pool);

        self::assertTrue($tokens->claim('abc', 60));
        $marker = $pool->getItem($this->key('abc'))->get();
        self::assertIsString($marker);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $marker);
    }

    /**
     * A lock store or a pool may log or list its keys: the nonce reaches neither in clear, only its SHA-256, which also
     * keeps a nonce with characters PSR-6 reserves a valid cache key. A replay is still refused, with or without a lock.
     */
    public function testNoLockOrCacheKeyHoldsTheNonce(): void
    {
        $nonce = 'n0nce{secret}/value:@';
        $store = new KeyRecordingLockStore();
        $pool = new KeyRecordingPool();
        $tokens = new CacheUsedTokens($pool, new LockFactory($store));

        self::assertTrue($tokens->claim($nonce, 60));
        self::assertFalse($tokens->claim($nonce, 60), 'A replay under a lock is refused.');
        self::assertNotSame([], $store->keys);
        self::assertNotSame([], $pool->keys);
        self::assertSame([$this->key($nonce)], array_values(array_unique([...$store->keys, ...$pool->keys])));

        foreach ([...$store->keys, ...$pool->keys] as $key) {
            self::assertStringNotContainsString('secret', $key);
            self::assertStringNotContainsString($nonce, $key);
        }

        $unlocked = new KeyRecordingPool();
        $withoutLock = new CacheUsedTokens($unlocked);
        self::assertTrue($withoutLock->claim($nonce, 60));
        self::assertFalse($withoutLock->claim($nonce, 60), 'A replay without a lock is refused.');
        self::assertSame([$this->key($nonce)], array_values(array_unique($unlocked->keys)));
    }

    public function testTheKeyOfANonceIsItsSha256AfterThePrefix(): void
    {
        $pool = new ArrayAdapter();

        self::assertTrue(new CacheUsedTokens($pool)->claim('abc', 60));
        self::assertTrue($pool->getItem('phil_spam_protection.ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')->isHit());
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

    private function key(string $nonce): string
    {
        return 'phil_spam_protection.'.hash('sha256', $nonce);
    }
}
