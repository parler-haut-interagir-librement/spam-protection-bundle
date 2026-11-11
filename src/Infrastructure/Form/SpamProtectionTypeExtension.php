<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Infrastructure\Form;

use Override;
use Phil\SpamProtection\Inspection\Signal;
use Phil\SpamProtectionBundle\Application\Submission;
use Phil\SpamProtectionBundle\Application\SubmissionInspector;
use Phil\SpamProtectionBundle\Infrastructure\Http\ClientKey;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Protects a form against spam when it asks to:
 *
 * ```php
 * $builder->setDefault('spam_protection', ['scope' => 'contact', 'content_fields' => ['message']]);
 * // or, with the form's name as scope and no text inspected:
 * $resolver->setDefault('spam_protection', true);
 * ```
 *
 * The form then carries three unmapped fields — a honeypot a person never sees, the signed token, and the proof of
 * work the browser's Stimulus controller fills —, and after submission the inspector reads them with the text of `content_fields`. A
 * submission held for spam gets one form error, which says nothing of why; the reasons go to the log, with the
 * scope and never the client.
 *
 * **A form shown again after a submission carries a fresh token.** The submitted token is claimed by the
 * inspection, and the proof's challenge is the nonce of the token issued when the form was built for this request;
 * so the view renders that new token beside its challenge, and an empty proof for the browser to solve again,
 * rather than the submitted values. A person who corrects a field and submits again is not held for replaying a
 * token.
 */
final class SpamProtectionTypeExtension extends AbstractTypeExtension
{
    public const string HONEYPOT = 'website_url';

    public const string TOKEN = '_phil_spam_token';

    public const string PROOF = '_phil_spam_proof';

    /** The form attribute holding the token issued when the form was built, which its view renders. */
    private const string ISSUED_TOKEN = 'phil_spam_protection.token';

    /** The Stimulus controller that solves the proof, as Symfony UX names it from `assets/package.json`. */
    public const string CONTROLLER = 'phil--spam-protection-bundle--proof-of-work';

    public function __construct(
        private readonly SubmissionInspector $inspector,
        private readonly ?ClientKey $clientKey = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[Override]
    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->define('spam_protection')
            ->default(false)
            ->allowedTypes('bool', 'array')
            ->info('true, or ["scope" => string, "content_fields" => list<string>], to protect the form against spam.')
            ->normalize(static function (Options $options, bool|array $protection): ?array {
                if (false === $protection) {
                    return null;
                }

                $protection = true === $protection ? [] : $protection;
                $scope = $protection['scope'] ?? null;
                $fields = $protection['content_fields'] ?? [];

                return [
                    'scope' => \is_string($scope) && '' !== $scope ? $scope : null,
                    'content_fields' => \is_array($fields) && array_all($fields, static fn (mixed $field): bool => \is_string($field)) ? $fields : throw new InvalidOptionsException('The "content_fields" of "spam_protection" are a list of field names.'),
                ];
            });
    }

    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $protection = $options['spam_protection'];

        if (!\is_array($protection)) {
            return;
        }

        /** @var array{scope: ?string, content_fields: array<string>} $protection */
        $scope = $protection['scope'] ?? $builder->getName();
        $issued = $this->inspector->protect($scope);

        $builder
            ->setAttribute(self::ISSUED_TOKEN, $issued->token)
            ->add(self::HONEYPOT, TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => false,
                'attr' => ['autocomplete' => 'off', 'tabindex' => '-1'],
                'row_attr' => ['style' => 'position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden', 'aria-hidden' => 'true'],
            ])
            ->add(self::TOKEN, HiddenType::class, ['mapped' => false, 'data' => $issued->token])
            ->add(self::PROOF, HiddenType::class, [
                'mapped' => false,
                'attr' => [
                    'data-controller' => self::CONTROLLER,
                    'data-'.self::CONTROLLER.'-challenge-value' => $issued->challenge,
                    'data-'.self::CONTROLLER.'-difficulty-value' => (string) $issued->difficulty,
                ],
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($scope, $protection): void {
            $this->inspect($event->getForm(), $scope, $protection['content_fields']);
        });
    }

    /**
     * Renders the token issued for this request and an empty proof, whatever was submitted: the challenge beside
     * them belongs to that token, and the submitted one is already claimed.
     */
    #[Override]
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $token = $form->getConfig()->getAttribute(self::ISSUED_TOKEN);

        if (!\is_string($token) || !isset($view->children[self::TOKEN], $view->children[self::PROOF])) {
            return;
        }

        $view->children[self::TOKEN]->vars['value'] = $token;
        $view->children[self::PROOF]->vars['value'] = '';
    }

    /**
     * @param FormInterface<mixed> $form
     * @param array<string>        $contentFields
     */
    private function inspect(FormInterface $form, string $scope, array $contentFields): void
    {
        $inspection = $this->inspector->inspect(new Submission(
            $scope,
            self::text($form, self::HONEYPOT),
            self::text($form, self::TOKEN),
            self::text($form, self::PROOF),
            array_filter(array_map(static fn (string $field): ?string => self::text($form, $field), $contentFields), \is_string(...)),
            $this->clientKey?->current(),
        ));

        if (!$inspection->isSpam()) {
            return;
        }

        $this->logger?->warning('A submission of the form "{scope}" was held for spam: {signals}.', [
            'scope' => $scope,
            'signals' => implode(', ', array_map(static fn (Signal $signal): string => $signal->value, $inspection->signals)),
        ]);

        $template = 'phil_spam_protection.rejected';
        $message = $this->translator?->trans($template, [], 'phil_spam_protection') ?? 'This submission could not be accepted. Please try again.';
        $form->addError(new FormError($message, $template, [], null, $inspection));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function text(FormInterface $form, string $field): ?string
    {
        if (!$form->has($field)) {
            return null;
        }

        $data = $form->get($field)->getData();

        return \is_string($data) ? $data : null;
    }
}
