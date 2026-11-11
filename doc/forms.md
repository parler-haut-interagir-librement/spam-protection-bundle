# Protecting a form

Any form takes the `spam_protection` option:

```php
public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults([
        'data_class' => ContactRequest::class,
        'spam_protection' => ['scope' => 'contact', 'content_fields' => ['message', 'subject']],
    ]);
}
```

| Value | Meaning |
|---|---|
| `false` (default) | No protection. |
| `true` | Protection, scoped by the form's name, no text inspected. |
| `['scope' => …, 'content_fields' => […]]` | The scope — which form the token and the rate limit belong to — and the fields whose text the content rules read. A field that is not a string, or a list that is not one of names, is refused. |

## The three fields

The form gets three fields, none mapped to its data:

- **`website_url`**, the honeypot: a text field moved off-screen, hidden from assistive technology, out of the tab
  order and without autocompletion. A person never fills it; a robot filling every field does. Give no field of your
  own that name.
- **`_phil_spam_token`**, hidden: the signed token issued when the form was built.
- **`_phil_spam_proof`**, hidden: where the Stimulus controller `phil--spam-protection-bundle--proof-of-work`
  writes the proof of work, from the challenge and difficulty of its values.

They render with the rest of the form — `form_rest(form)` or `form_end(form)` covers them.

## A held submission

After submission, the inspector reads the three fields and the `content_fields`. A submission held for spam gets
**one error on the form**, `phil_spam_protection.rejected` in the `phil_spam_protection` domain — "This submission
could not be accepted. Please try again." —, which never says why: telling a robot which check it failed teaches it
to pass. The error's cause is the `Inspection`, with the signals.

The reasons go to the log, on the `spam_protection` channel, at warning level, with the scope and the signals —
never the address, the token or the text.

A form shown again after its submission — held for spam, or with a field to correct — renders the token issued
for that request and an empty proof, never the token that was submitted: the submitted token is claimed by the
inspection, and the proof's challenge on the page is the new token's nonce. A person whose submission is held — a
password manager filled the honeypot, the page was left open for hours — or who corrects a field submits again
with that new token and is not held for replaying the old one.
