<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Functional;

use Override;
use Phil\SpamProtection\Proof\ProofOfWork;
use Phil\SpamProtectionBundle\Infrastructure\Form\SpamProtectionTypeExtension;
use Phil\SpamProtectionBundle\PhilSpamProtectionBundle;
use Phil\SpamProtectionBundle\Tests\Functional\App\TestKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A contact form in a real kernel: rendered with its protection, submitted as a person's browser would — the proof
 * solved —, and as robots do.
 */
#[CoversClass(PhilSpamProtectionBundle::class)]
final class SpamProtectionTest extends KernelTestCase
{
    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    #[Override]
    protected function setUp(): void
    {
        if (!ContainerBuilder::willBeAvailable('symfony/event-dispatcher', EventDispatcher::class, ['symfony/dependency-injection'])) {
            self::markTestSkipped('The event dispatcher is installed for development only: no kernel can boot.');
        }

        self::bootKernel();
        $cache = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();
    }

    public function testAPersonsSubmissionIsAccepted(): void
    {
        $fields = $this->render();

        self::assertSame(['valid' => true, 'errors' => []], $this->submit($this->person($fields, 'Hello, a quote please.')));
    }

    public function testARobotIsHeldWithATranslatedMessage(): void
    {
        $fields = $this->render();

        self::assertSame(['valid' => false, 'errors' => ['Cet envoi n’a pas pu être accepté. Veuillez réessayer.']], $this->submit([
            ...$this->person($fields, 'Hello'),
            SpamProtectionTypeExtension::HONEYPOT => 'https://spam.example',
        ]));
    }

    public function testTheProofTheBrowserSkipsIsMissed(): void
    {
        $fields = $this->render();

        self::assertFalse($this->submit([...$this->person($fields, 'Hello'), SpamProtectionTypeExtension::PROOF => ''])['valid']);
    }

    public function testATokenIsUsedOnce(): void
    {
        $submission = $this->person($this->render(), 'Hello');

        self::assertTrue($this->submit($submission)['valid']);
        self::assertFalse($this->submit($submission)['valid']);
    }

    public function testAForbiddenTermIsHeld(): void
    {
        self::assertFalse($this->submit($this->person($this->render(), 'Best casino online'))['valid']);
    }

    public function testTheRateLimitHoldsTheFourthSubmission(): void
    {
        foreach ([true, true, true, false] as $accepted) {
            self::assertSame($accepted, $this->submit($this->person($this->render(), 'Hello'))['valid']);
        }
    }

    /**
     * The protection the rendered form carries: its token, and the proof's challenge and difficulty.
     *
     * @return array{token: string, challenge: string, difficulty: int}
     */
    private function render(): array
    {
        $fields = json_decode((string) $this->handle(Request::create('/contact'))->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($fields);
        self::assertSame([SpamProtectionTypeExtension::HONEYPOT, SpamProtectionTypeExtension::TOKEN, SpamProtectionTypeExtension::PROOF, 'message'], array_keys($fields));

        $tokenField = $fields[SpamProtectionTypeExtension::TOKEN];
        $proofField = $fields[SpamProtectionTypeExtension::PROOF];
        self::assertIsArray($tokenField);
        self::assertIsArray($proofField);
        self::assertIsArray($proofField['attr'] ?? null);

        $token = $tokenField['value'] ?? null;
        $challenge = $proofField['attr']['data-'.SpamProtectionTypeExtension::CONTROLLER.'-challenge-value'] ?? null;
        $difficulty = $proofField['attr']['data-'.SpamProtectionTypeExtension::CONTROLLER.'-difficulty-value'] ?? null;
        self::assertIsString($token);
        self::assertIsString($challenge);
        self::assertIsString($difficulty);

        return ['token' => $token, 'challenge' => $challenge, 'difficulty' => (int) $difficulty];
    }

    /**
     * What a person's browser sends: the message, an empty honeypot, the token, and the proof solved.
     *
     * @param array{token: string, challenge: string, difficulty: int} $protection
     *
     * @return array<string, string>
     */
    private function person(array $protection, string $message): array
    {
        $proof = new ProofOfWork($protection['difficulty']);
        $solution = 0;

        while (!$proof->verify($protection['challenge'], (string) $solution)) {
            ++$solution;
        }

        return [
            'message' => $message,
            SpamProtectionTypeExtension::HONEYPOT => '',
            SpamProtectionTypeExtension::TOKEN => $protection['token'],
            SpamProtectionTypeExtension::PROOF => (string) $solution,
        ];
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{valid: bool, errors: list<string>}
     */
    private function submit(array $fields): array
    {
        $outcome = json_decode((string) $this->handle(Request::create('/contact', 'POST', ['contact' => $fields], server: ['REMOTE_ADDR' => '198.51.100.4']))->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($outcome);
        self::assertIsBool($outcome['valid'] ?? null);
        self::assertIsArray($outcome['errors'] ?? null);

        return ['valid' => $outcome['valid'], 'errors' => array_values(array_filter($outcome['errors'], \is_string(...)))];
    }

    private function handle(Request $request): Response
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);

        return $kernel->handle($request);
    }
}
