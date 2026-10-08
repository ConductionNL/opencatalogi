# Design: the-public-and-community-surface

## D1. A notice is not a publication, and both are objects

A publication is a document the Woo obliges us to hold. A notice is
something a municipality wants to say this week. They have different
lifetimes, different obligations and different readers. So `notice` is
its own schema in the catalogue, with a title, a body, a period and an
optional link to a publication.

Reusing `publication` for a notice would put a storingsmelding in the
sitemap and in the DiWoo feed. That is the wrong place.

## D2. The status page reads components, not guesses

`serviceStatus` names a component a reader recognises (DigiD, de balie,
het zwembad) and carries its current state and a short message. An
administrator sets it, or an integration does. The page renders the
components and their history.

The page does not probe anything. A status page that infers a component's
health from a health check tells the reader that the monitoring is up,
which is the failure mode every incident starts with.

## D3. Subscriptions are notifications, not a mail list of our own

A subscription to the status page or to a notice board is a notification
subscription under ADR-031. We do not build a second mailing mechanism,
and the reader's address lives where addresses live.

An anonymous reader subscribes with a verified address and a one-time
confirmation. Without the confirmation there is no subscription, because
subscribing somebody else to an alert stream is a way to send mail on
somebody's behalf.

## D4. The banner belongs to the instance, the notice to the catalogue

`instanceBanner` has a body, a period, a severity and a dismissable flag.
It shows to every signed-in user between the two dates and it shows once
to a reader who dismissed it, exactly as GitLab's broadcast message does.
Nothing about it is per catalogue, because planned maintenance is not per
catalogue.

## D5. The feed is Atom, and it is the activity we already have

A catalogue's feed is its published and updated records, plus its notices,
as Atom. Kanboard and Vikunja both chose a feed for the same reason: a
ketenpartner who wants to watch without an account and without a webhook.

The feed is public, so it carries exactly what an anonymous reader may
read and no more. Its access check is the publication's, run per entry,
not a separate rule.

## D6. A vote is one per reader, and the reader is not staff

`vote` carries the record, a reader token and a value. One vote per
reader per record. The distribution is readable; the individual votes are
not, because a vote on an inspraak item is an opinion attached to a
person.

The reader token is the portal identity when there is one and a signed
cookie when there is not. Neither is a name. Participatie and inspraak
are the use, and both are acts where the count is the point.

## D7. The markup endpoint renders, it never stores

One endpoint, in and out, no side effect. A client posts our markup and
gets back the HTML we would render. Forgejo and Gitea both ship this for
the same reason: a mobile client that renders its own markdown shows
something different from the website, and the difference is a defect
report nobody can reproduce.

## Risks

- **A status page that is out of date is worse than none.** A component
  whose state has not been touched for a configured period is shown with
  the date it was last set, rather than as a confident green.
- **Comments on a notice are a moderation duty.** Comments are off by
  default per notice board, and a board that enables them names a
  moderator.
- **Vote brigading.** One vote per reader token, throttled per address
  under ADR-082, and the count is reported with the method beside it.
- **The feed leaking a draft.** The access check runs per entry against
  the publication, and the tests assert a draft is absent.
