<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Functional\App;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A contact form protected against spam: GET gives the fields a browser would render, POST the outcome.
 */
final readonly class ContactController
{
    public function __construct(
        private FormFactoryInterface $forms,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $form = $this->forms->createNamed('contact', FormType::class, null, ['spam_protection' => ['content_fields' => ['message']]])
            ->add('message', TextareaType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            $view = $form->createView();
            $fields = [];

            foreach ($view->children as $name => $child) {
                $fields[$name] = ['value' => $child->vars['value'], 'attr' => $child->vars['attr']];
            }

            return new JsonResponse($fields);
        }

        return new JsonResponse(['valid' => $form->isValid(), 'errors' => array_map(static fn (FormError|FormErrorIterator $error): string => $error->getMessage(), iterator_to_array($form->getErrors()))]);
    }
}
