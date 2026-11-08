<?php

declare(strict_types=1);

namespace App\Services\Inbox\Providers;

use App\Dto\Inbox\InboxEntry;
use App\Dto\Inbox\InboxFetchResult;
use App\Exceptions\Inbox\InboxException;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads one channel's inbox and answers in it, through the network's own API.
 * Every read is a poll: the app may run on a machine no webhook can reach.
 */
abstract class AbstractInboxProvider
{
    abstract public function fetch(SocialAccount $account): InboxFetchResult;

    /**
     * Sends the reply and returns it as the network stored it.
     *
     * @throws InboxException
     */
    abstract public function reply(InboxConversation $conversation, SocialAccount $account, string $text): InboxEntry;

    /**
     * How long to wait between two reads of the same channel.
     */
    public function syncIntervalMinutes(): int
    {
        return (int) config('trypost.inbox.sync_interval_minutes');
    }

    /**
     * An HTTP client authenticated as the channel, refreshing an expired token
     * first so no request is spent on a token the network will refuse.
     */
    protected function client(SocialAccount $account): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->accessToken($account))
            ->timeout(30);
    }

    protected function accessToken(SocialAccount $account): string
    {
        if ($account->needsProactiveTokenRefresh()) {
            try {
                app(ConnectionVerifier::class)->refreshToken($account);
            } catch (TokenExpiredException $exception) {
                throw new InboxException('authentication', $exception->getMessage());
            } catch (PlatformUnavailableException $exception) {
                throw new InboxException('transient', $exception->getMessage());
            }
        }

        return (string) $account->access_token;
    }

    /**
     * @throws InboxException
     */
    protected function ensureSuccessful(Response $response, string $operation): Response
    {
        if ($response->successful()) {
            return $response;
        }

        throw InboxException::fromResponse($response, $operation);
    }

    protected function time(mixed $value): CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse((string) $value)->utc() : CarbonImmutable::now('UTC');
    }

    /**
     * The reply just sent, as the channel's own message. A network that
     * answers without an id gets a local one so the message is still kept.
     */
    protected function ownEntry(SocialAccount $account, ?string $remoteId, ?string $text, ?CarbonImmutable $sentAt = null): InboxEntry
    {
        return new InboxEntry(
            remoteId: filled($remoteId) ? (string) $remoteId : (string) Str::uuid7(),
            body: $text,
            sentAt: $sentAt ?? CarbonImmutable::now('UTC'),
            isOwn: true,
            authorName: $account->accountDisplayName(),
            authorUsername: $account->username,
        );
    }

    /**
     * Images, videos and files of a Meta message (Messenger or Instagram).
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return list<array{type: string, url: string}>|null
     */
    protected function metaAttachments(array $attachments): ?array
    {
        $items = collect($attachments)
            ->map(fn (array $attachment): ?array => match (true) {
                filled(data_get($attachment, 'image_data.url')) => ['type' => 'image', 'url' => (string) data_get($attachment, 'image_data.url')],
                filled(data_get($attachment, 'video_data.url')) => ['type' => 'video', 'url' => (string) data_get($attachment, 'video_data.url')],
                filled(data_get($attachment, 'file_url')) => ['type' => 'file', 'url' => (string) data_get($attachment, 'file_url')],
                default => null,
            })
            ->filter()
            ->values()
            ->all();

        return filled($items) ? $items : null;
    }

    /**
     * Plain text of a network's HTML body (Mastodon statuses): line breaks
     * kept, tags dropped, entities decoded.
     */
    protected function plainText(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        $text = preg_replace('/<br\s*\/?>/i', "\n", (string) $html);
        $text = preg_replace('/<\/p>\s*<p[^>]*>/i', "\n\n", (string) $text);

        return Str::trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));
    }
}
