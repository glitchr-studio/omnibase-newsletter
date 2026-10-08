# Newsletter

A newsletter for [omnibase](https://github.com/glitchr-studio/omnibase), with
no account behind it: the visitor leaves an address, confirms it from the mail
it receives, and gets the letters the site writes in its back office - each
carrying its way out. First made for two musicians' sites (a harpist, a
violinist), where it is the only thing one can subscribe to.

A `Subscriber` is an address (unique, lowercased), the key of its links (a
32-hex token), the language of the page it signed up from, where it came from
(`source`: `site`, `import`, a page's key) and its history: `createdAt`,
`confirmedAt`, `unsubscribedAt`. Leaving keeps the row - nothing more is sent
to it, the history is known - and coming back is a new confirmation, under a
new token.

A `Campaign` is a letter: a subject, a text (plain, a blank line between two
paragraphs), an optional button (`link`, `linkLabel`), who it goes to (one
`locale`, or everyone), its state (`draft`, `sending`, `sent`) and how many
it went to.

## Install

```bash
composer require omnibase/newsletter:dev-main
```

```php
// config/bundles.php
Base\Newsletter\NewsletterBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
newsletter_controller:
    resource: "@NewsletterBundle/src/Controller/Client"
    type: attribute
    prefix: /
```

```yaml
# config/packages/newsletter.yaml (every key optional)
newsletter:
    double_opt_in: true     # nothing is sent before the address confirms
    min_delay: 3            # seconds a human needs to fill the form
    flood_interval: 60      # seconds between two sign-ups from one address
    from_name: null         # the sender's name; null: the site's (base.settings.mail.name)
    digest:
        enabled: true
        title: null         # "{month}" replaced; null: "Your dates in {month}", translated
```

```yaml
# config/packages/messenger.yaml: a letter goes out in the background
framework:
    messenger:
        routing:
            Base\Newsletter\Message\SendCampaignMessage: async
```

Then `bin/console doctrine:migrations:diff && bin/console doctrine:migrations:migrate`
and `bin/console assets:install` (the stylesheet lives in `public/css/newsletter.css`).

A worker sending letters builds absolute links without a request: set
`framework.router.default_uri` (e.g. `'%env(DEFAULT_URI)%'`), or the links
of the mails point at `localhost`.

## Routes

| Route                    | Path                                    |                                                         |
|--------------------------|-----------------------------------------|---------------------------------------------------------|
| `newsletter_index`       | `GET /newsletter`                       | a page with the form and what to expect, in the sitemap |
| `newsletter_subscribe`   | `POST /newsletter`                      | the form; back to the page it was filled on, `#newsletter`, with a flash |
| `newsletter_confirm`     | `GET /newsletter/confirm/{token}`       | the confirmation mail's link                            |
| `newsletter_unsubscribe` | `GET\|POST /newsletter/unsubscribe/{token}` | GET: a page with one button; POST: unsubscribed    |

## Double opt-in

1. The visitor fills the form. It is guarded as glitchr/omnibase guards a
   form (its option `guard`, `action: newsletter`): a trap, the time it takes
   (`min_delay`), the lists, the captcha when the site has glitchr/omniguard.
   With a glitchr/omnibase from before the guard, the form's own trap field
   (`website`) and signed timestamp (`opened_at`, an HMAC with the kernel's
   secret) do that work. Then the flood
   interval the second sign-up from one address, and the rate limiter
   `newsletter_subscribe` (5 an hour per visitor, declared by the bundle when
   `symfony/rate-limiter` is there; a host's own
   `framework.rate_limiter.newsletter_subscribe` overrides it).
2. The answer is the same whatever the address: "check your inbox". It never
   says whether the address was already on the list. Only an address that has
   not confirmed receives a mail.
3. The mail's link (`newsletter_confirm`) puts it on the list. An old link of
   an address that left since does not bring it back.
4. Every letter carries a link to `newsletter_unsubscribe` and the headers
   `List-Unsubscribe: <…>` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
   (RFC 8058): the mail client's own "unsubscribe" button POSTs to it. The
   link itself opens a page with one button - mail scanners follow links, a
   GET never unsubscribes anyone.

With `double_opt_in: false` the address is on the list at once.

## The mails

The confirmation goes through omnibase's notifier (`send('email')`), from the
site's address. A letter does not: omnibase's `Notification` builds its
e-mail itself and offers no way to add a header, so letters are
`TemplatedEmail`s sent with Symfony's `MailerInterface`, from the same
address (`NotifierInterface::getTechnicalRecipient()`), named after
`from_name` when it is set. With the mailer routed to Messenger, each mail is
queued too.

`Message\SendCampaignMessage` is handled by `MessageHandler\SendCampaignHandler`:
one mail per confirmed subscriber (of the letter's language when it has one),
each remembered on the subscriber as it goes so a retried send does not write
twice to the same address; then the letter is `sent`.

## The monthly digest

A service implementing `Base\Newsletter\Digest\DigestSourceInterface` is
found by itself (tag `newsletter.digest_source`):

```php
final class ConcertDigest implements DigestSourceInterface
{
    public function getName(): string { return 'Concerts'; }

    public function digest(\DateTimeImmutable $from, \DateTimeImmutable $to, string $locale): array
    {
        return array_map(fn (Concert $c) => [
            'title' => $c->getTitle(), 'text' => $c->getVenue(), 'url' => $c->getUrl(), 'date' => $c->getDate(),
        ], $this->concerts->findBetween($from, $to));
    }
}
```

`bin/console newsletter:draft-digest [--from=Y-m-d] [--to=Y-m-d] [--locale=de]`
asks every source for the coming month (by default) and writes a DRAFT
campaign - "Your dates in November 2026" - with a part per source, its
entries in date order. Nothing is sent: it waits in the back office to be
read and sent. A cron on the 20th of each month is the usual way.

## What the host provides

The templates extend `layout1.html.twig` and fill `title`, `description`,
`content` and `stylesheets`. The form goes in a footer with

```twig
{% include '@Newsletter/client/_form.html.twig' %}
{% include '@Newsletter/client/_form.html.twig' with {source: 'home', heading: false} %}
```

and two Twig functions help: `newsletter_form(source = 'site')` (a FormView)
and `newsletter_count(locale = null)` (the confirmed subscribers).

Every template is overridden the Symfony way, from
`templates/bundles/NewsletterBundle/` - the mails too:
`templates/bundles/NewsletterBundle/email/campaign.html.twig` gets
`subject`, `campaign`, `link`, `site_title` (`base.settings.title`),
`locale`, `unsubscribe`, `home`, `test`.

The back office gets a `Campaign` CRUD (send me a test, send - with a
confirmation), a `Subscriber` CRUD (filters confirmed / left, language,
source; CSV export of the confirmed addresses) and a dashboard widget,
`newsletter_subscribers`.

## Tests

```bash
vendor/bin/phpunit
```

The unit tests (`tests/Entity`, `tests/Service`) need no kernel.

## License

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
