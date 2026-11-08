<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Http\Requests\App\X\StoreAssistedXAccountRequest;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use App\Support\Social\AssistedPosting;
use App\Support\Social\PendingConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class XController extends SocialController
{
    protected string $driver = 'x';

    protected SocialPlatform $platform = SocialPlatform::X;

    protected array $scopes = [
        'tweet.read',
        'tweet.write',
        'users.read',
        'media.write',
        'offline.access',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        if (AssistedPosting::enabled()) {
            $this->rememberConnectSession($request, $workspace);

            return Inertia::render('accounts/XAssistedConnect', [
                'backUrl' => PendingConnection::current()?->returnUrl() ?? PendingConnection::defaultReturnUrl(),
            ])->toResponse($request);
        }

        return $this->redirectToProvider($request, $this->driver, $this->scopes);
    }

    /**
     * Assisted posting: the account is added by its handle, without the X API.
     * It ends on the same confirmation page as every connection.
     */
    public function storeAssisted(StoreAssistedXAccountRequest $request): RedirectResponse
    {
        $this->ensurePlatformEnabled();
        abort_unless(AssistedPosting::enabled(), Response::HTTP_NOT_FOUND);

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        if (PendingConnection::current()?->platform() !== $this->platform) {
            $this->rememberConnectSession($request, $workspace);
        }

        $handle = $request->handle();
        $handleKey = mb_strtolower($handle);

        return $this->offerIdentities($workspace, [
            PendingConnection::identity(
                $this->platform,
                "assisted:{$handleKey}",
                "@{$handle}",
                $handle,
                null,
                $this->platform->identityType()->value,
                [
                    'username' => $handle,
                    'display_name' => "@{$handle}",
                    'is_assisted' => true,
                    'access_token' => '',
                    'refresh_token' => null,
                    'token_expires_at' => null,
                    'scopes' => [],
                ],
            ),
        ], $this->reconnectAccount($workspace));
    }

    public function callback(Request $request): RedirectResponse
    {
        return $this->handleCallback($request, $this->driver, $this->scopes);
    }

    protected function afterConnected(SocialAccount $account): void
    {
        if ($account->isAssisted()) {
            return;
        }

        rescue(fn (): bool => app(ConnectionVerifier::class)->verifyAccessToken($account), report: false);
    }
}
