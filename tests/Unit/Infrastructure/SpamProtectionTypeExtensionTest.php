<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Phil\SpamProtection\Content\ContentRules;
use Phil\SpamProtection\Inspection\Inspection;
use Phil\SpamProtection\Inspection\Signal;
use Phil\SpamProtection\Proof\ProofOfWork;
use Phil\SpamProtection\Timing\TimingPolicy;
use Phil\SpamProtection\Token\TokenCodec;
use Phil\SpamProtectionBundle\Application\SubmissionInspector;
use Phil\SpamProtectionBundle\Infrastructure\Form\SpamProtectionTypeExtension;
use Phil\SpamProtectionBundle\Infrastructure\Http\ClientKey;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\CountingRateLimiter;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\MemoryUsedTokens;
use Phil\SpamProtectionBundle\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

#[CoversClass(SpamProtectionTypeExtension::class)]
final class SpamProtectionTypeExtensionTest extends TestCase
{
    private MockClock $clock;

    private CountingRateLimiter $limiter;

    private RecordingLogger $logger;

    private MemoryUsedTokens $usedTokens;

    protected function setUp(): void
    {
        $this->usedTokens = new MemoryUsedTokens();
        $this->clock = new MockClock(new DateTimeImmutable('@1700000000'));
        $this->limiter = new CountingRateLimiter(10);
        $this->logger = new RecordingLogger();
    }

    public function testItExtendsEveryForm(): void
    {
        self::assertSame([FormType::class], [...SpamProtectionTypeExtension::getExtendedTypes()]);
    }

    public function testAFormWithoutTheOptionIsLeftAlone(): void
    {
        $form = $this->factory()->createNamed('contact', FormType::class);

        self::assertFalse($form->has(SpamProtectionTypeExtension::HONEYPOT));
        self::assertFalse($form->has(SpamProtectionTypeExtension::TOKEN));
    }

    public function testAProtectedFormCarriesTheThreeUnmappedFields(): void
    {
        $form = $this->contact(true);
        $view = $form->createView();

        foreach ([SpamProtectionTypeExtension::HONEYPOT, SpamProtectionTypeExtension::TOKEN, SpamProtectionTypeExtension::PROOF] as $field) {
            self::assertFalse($form->get($field)->getConfig()->getMapped(), $field);
        }

        self::assertFalse($form->get(SpamProtectionTypeExtension::HONEYPOT)->getConfig()->getRequired());
        self::assertSame(['autocomplete' => 'off', 'tabindex' => '-1'], $form->get(SpamProtectionTypeExtension::HONEYPOT)->getConfig()->getOption('attr'));
        $row = $form->get(SpamProtectionTypeExtension::HONEYPOT)->getConfig()->getOption('row_attr');
        self::assertIsArray($row);
        self::assertSame('true', $row['aria-hidden']);
        self::assertIsString($row['style']);
        self::assertStringContainsString('left:-10000px', $row['style']);
        self::assertFalse($form->get(SpamProtectionTypeExtension::HONEYPOT)->getConfig()->getOption('label'));

        $token = new TokenCodec('a secret of sixteen bytes or more')->decode($this->token($form));
        self::assertNotNull($token);
        self::assertSame('contact', $token->scope);

        $proof = $view->children[SpamProtectionTypeExtension::PROOF]->vars['attr'] ?? [];
        self::assertSame([
            'data-controller' => 'phil--spam-protection-bundle--proof-of-work',
            'data-phil--spam-protection-bundle--proof-of-work-challenge-value' => $token->nonce,
            'data-phil--spam-protection-bundle--proof-of-work-difficulty-value' => '0',
        ], $proof);
    }

    public function testAPersonsSubmissionIsAccepted(): void
    {
        $form = $this->contact(['content_fields' => ['message']]);
        $this->clock->sleep(10);
        $form->submit($this->submission($form, ['name' => 'Ada', 'message' => 'Hello there, a quote please.']));

        self::assertTrue($form->isValid());
        self::assertSame(['name' => 'Ada', 'message' => 'Hello there, a quote please.'], $form->getData());
        self::assertSame([], $this->logger->records);
    }

    /**
     * Symfony Form does not check that a text is UTF-8: a robot that posts other bytes gets its submission held, not a
     * server error.
     */
    public function testATextThatIsNotUtf8IsHeldAsMalformed(): void
    {
        $form = $this->contact(['content_fields' => ['message']]);
        $this->clock->sleep(10);
        $form->submit($this->submission($form, ['name' => 'Ada', 'message' => "hello \xff world"]));

        self::assertFalse($form->isValid());
        $cause = $form->getErrors()[0]->getCause();
        self::assertInstanceOf(Inspection::class, $cause);
        self::assertSame([Signal::Malformed], $cause->signals);
        self::assertSame([['warning', 'A submission of the form "{scope}" was held for spam: {signals}.', ['scope' => 'contact', 'signals' => 'malformed']]], $this->logger->records);
    }

    public function testASubmissionHeldForSpamGetsOneErrorAndALogWithoutTheClient(): void
    {
        $form = $this->contact(['scope' => 'contact-us', 'content_fields' => ['message', 'missing', 'name']]);
        $form->submit($this->submission($form, ['name' => 'qwrtzpsdfghjkl', 'message' => 'Hello'], honeypot: 'filled'));

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->getErrors());

        $error = $form->getErrors()[0];
        self::assertSame('Cet envoi n’a pas pu être accepté.', $error->getMessage());
        self::assertSame('phil_spam_protection.rejected', $error->getMessageTemplate());
        $cause = $error->getCause();
        self::assertInstanceOf(Inspection::class, $cause);
        self::assertSame([Signal::HoneypotFilled, Signal::TooFast, Signal::Gibberish], $cause->signals);

        self::assertSame([['warning', 'A submission of the form "{scope}" was held for spam: {signals}.', ['scope' => 'contact-us', 'signals' => 'honeypot_filled, too_fast, gibberish']]], $this->logger->records);
        self::assertSame(['contact-us|'.hash_hmac('sha256', '203.0.113.7', 'k') => 1], $this->limiter->counts);
    }

    /**
     * A form is shown again after its submission — a field to correct, say —, and the person submits it again: the
     * second submission carries the token rendered with the form, not the one the first submission claimed.
     */
    public function testAFormShownAgainAfterASubmissionCarriesAFreshTokenAndProof(): void
    {
        $codec = new TokenCodec('a secret of sixteen bytes or more');
        $claimed = $this->token($this->contact(['content_fields' => ['message']]));

        $this->clock->sleep(10);
        $posted = $this->contact(['content_fields' => ['message']]);
        $posted->submit([...$this->submission($posted, ['name' => 'Ada', 'message' => 'Hello']), SpamProtectionTypeExtension::TOKEN => $claimed, SpamProtectionTypeExtension::PROOF => 'stale']);
        self::assertTrue($posted->isValid());

        $view = $posted->createView();
        $rendered = $view->children[SpamProtectionTypeExtension::TOKEN]->vars['value'];
        self::assertIsString($rendered);
        self::assertNotSame($claimed, $rendered);
        $token = $codec->decode($rendered);
        self::assertNotNull($token);
        $attr = $view->children[SpamProtectionTypeExtension::PROOF]->vars['attr'];
        self::assertIsArray($attr);
        self::assertSame($token->nonce, $attr['data-phil--spam-protection-bundle--proof-of-work-challenge-value']);
        self::assertSame('', $view->children[SpamProtectionTypeExtension::PROOF]->vars['value']);

        $this->clock->sleep(10);
        $replayed = $this->contact(['content_fields' => ['message']]);
        $replayed->submit([...$this->submission($replayed, ['name' => 'Ada', 'message' => 'Hello again']), SpamProtectionTypeExtension::TOKEN => $claimed]);
        self::assertFalse($replayed->isValid(), 'the claimed token is refused');

        $corrected = $this->contact(['content_fields' => ['message']]);
        $corrected->submit([...$this->submission($corrected, ['name' => 'Ada', 'message' => 'Hello again']), SpamProtectionTypeExtension::TOKEN => $rendered]);
        self::assertTrue($corrected->isValid(), 'the token rendered after a submission is accepted');
    }

    public function testAFormNeverSubmittedRendersItsOwnToken(): void
    {
        $form = $this->contact(true);

        self::assertSame($this->token($form), $form->createView()->children[SpamProtectionTypeExtension::TOKEN]->vars['value']);
    }

    /**
     * A form whose proof field was taken out after it was built is rendered without it, its token left as it was.
     */
    public function testAFormWithoutItsProofFieldIsRenderedAsItIs(): void
    {
        $form = $this->contact(true);
        $token = $this->token($form);
        $form->remove(SpamProtectionTypeExtension::PROOF);

        $view = $form->createView();

        self::assertArrayNotHasKey(SpamProtectionTypeExtension::PROOF, $view->children);
        self::assertSame($token, $view->children[SpamProtectionTypeExtension::TOKEN]->vars['value']);
    }

    public function testWithoutTranslatorTheMessageIsInEnglish(): void
    {
        $form = $this->factory(translated: false)->createNamed('contact', FormType::class, null, ['spam_protection' => true]);
        $form->submit([]);

        self::assertSame('This submission could not be accepted. Please try again.', $form->getErrors()[0]->getMessage());
    }

    public function testTheScopeDefaultsToTheFormsName(): void
    {
        $form = $this->factory()->createNamed('signup', FormType::class, null, ['spam_protection' => ['scope' => '']]);

        self::assertSame('signup', new TokenCodec('a secret of sixteen bytes or more')->decode($this->token($form))?->scope);
    }

    public function testContentFieldsAreFieldNames(): void
    {
        $this->expectException(InvalidOptionsException::class);
        $this->expectExceptionMessageIsOrContains('The "content_fields" of "spam_protection" are a list of field names.');

        $this->factory()->createNamed('contact', FormType::class, null, ['spam_protection' => ['content_fields' => ['agree', 7]]]);
    }

    public function testContentFieldsAreAList(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->factory()->createNamed('contact', FormType::class, null, ['spam_protection' => ['content_fields' => 'message']]);
    }

    public function testWithoutClientKeyOrLoggerASubmissionIsStillInspected(): void
    {
        $form = Forms::createFormFactoryBuilder()
            ->addTypeExtension(new SpamProtectionTypeExtension($this->inspector()))
            ->getFormFactory()
            ->createNamed('contact', FormType::class, null, ['spam_protection' => true]);
        $form->submit([SpamProtectionTypeExtension::HONEYPOT => 'filled']);

        self::assertFalse($form->isValid());
        self::assertSame([], $this->limiter->counts);
    }

    public function testTheOptionIsABooleanOrAnArray(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->factory()->createNamed('contact', FormType::class, null, ['spam_protection' => 'yes']);
    }

    /**
     * @param bool|array<string, mixed> $protection
     *
     * @return FormInterface<mixed>
     */
    private function contact(bool|array $protection): FormInterface
    {
        return $this->factory()->createNamed('contact', FormType::class, null, ['spam_protection' => $protection])
            ->add('name', TextType::class)
            ->add('message', TextareaType::class);
    }

    /**
     * @param FormInterface<mixed>  $form
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function submission(FormInterface $form, array $fields, string $honeypot = ''): array
    {
        return [
            ...$fields,
            SpamProtectionTypeExtension::HONEYPOT => $honeypot,
            SpamProtectionTypeExtension::TOKEN => $form->get(SpamProtectionTypeExtension::TOKEN)->getData(),
            SpamProtectionTypeExtension::PROOF => '',
        ];
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function token(FormInterface $form): string
    {
        $token = $form->get(SpamProtectionTypeExtension::TOKEN)->getData();
        self::assertIsString($token);

        return $token;
    }

    private function factory(bool $translated = true): FormFactoryInterface
    {
        $requests = new RequestStack([Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.7'])]);

        $translator = new Translator('fr');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['phil_spam_protection.rejected' => 'Cet envoi n’a pas pu être accepté.'], 'fr', 'phil_spam_protection');

        return Forms::createFormFactoryBuilder()
            ->addTypeExtension(new SpamProtectionTypeExtension($this->inspector(), new ClientKey($requests, 'k'), $translated ? $translator : null, $this->logger))
            ->getFormFactory();
    }

    private function inspector(): SubmissionInspector
    {
        return new SubmissionInspector(
            new TokenCodec('a secret of sixteen bytes or more'),
            $this->clock,
            $this->usedTokens,
            new TimingPolicy(3, 3600),
            new ProofOfWork(0),
            new ContentRules(),
            $this->limiter,
        );
    }
}
