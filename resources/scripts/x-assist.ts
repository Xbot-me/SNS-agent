/**
 * Assisted posting for X: `npm run x:assist`.
 *
 * Watches for X posts that are due on assisted channels and hands each one to
 * you. Two ways, chosen with X_ASSIST_MODE:
 *
 * - `handoff` (default, macOS): opens X's compose page in the Firefox you
 *   already use and are signed in to, with the text filled in, and a Finder
 *   window with the media numbered in order to drag in. You press Post, then
 *   copy the post's link (Share > Copy link): the helper sees the link on the
 *   clipboard and records it. Nothing drives your browser.
 * - `automated`: opens Playwright's Firefox (its own profile, kept in
 *   storage/app/x-assist), fills in the text and attaches the media itself.
 *   X may refuse to sign in there, since it can tell the browser is automated.
 *
 * Either way it never presses Post and never solves a check X shows.
 */
import { execFileSync } from 'node:child_process';
import {
    copyFileSync,
    mkdirSync,
    mkdtempSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';

import { firefox, type BrowserContext, type Page } from 'playwright';

interface AssistedMedia {
    type: string | null;
    mime_type: string | null;
    path: string | null;
    url: string;
}

interface AssistedPost {
    id: string;
    handle: string;
    text: string;
    media: AssistedMedia[];
}

type Outcome =
    | { kind: 'posted'; url: string }
    | { kind: 'closed' }
    | { kind: 'timed_out' };

const ROOT = process.cwd();
const MODE =
    process.env.X_ASSIST_MODE === 'automated' ? 'automated' : 'handoff';
const PROFILE_DIR = join(ROOT, 'storage', 'app', 'x-assist', 'firefox-profile');
const OUTBOX_DIR = join(ROOT, 'storage', 'app', 'x-assist', 'outbox');
const INTENT_URL = 'https://x.com/intent/post';
const POLL_SECONDS = 20;
const SIGN_IN_MINUTES = 15;
const POST_MINUTES = 30;
const RETRY_AFTER_MINUTES = 10;
const MAX_TIMED_OUT_HANDOFFS = 3;
const COMPOSE_URL = 'https://x.com/compose/post';
const TEXTAREA = '[data-testid="tweetTextarea_0"]';
const FILE_INPUT = 'input[data-testid="fileInput"]';
const SENT_TOAST_LINK = '[data-testid="toast"] a[href*="/status/"]';

const sleep = (ms: number): Promise<void> =>
    new Promise((resolve) => setTimeout(resolve, ms));

const log = (message: string): void => {
    const time = new Date().toLocaleTimeString();
    console.log(`[${time}] ${message}`);
};

const artisan = (...args: string[]): string =>
    execFileSync('php', ['artisan', ...args, '--no-ansi'], {
        cwd: ROOT,
        encoding: 'utf8',
    });

const pendingPosts = (): AssistedPost[] => {
    const output = artisan('x-assist:pending').trim();

    return output === '' ? [] : (JSON.parse(output) as AssistedPost[]);
};

const preview = (text: string): string => {
    const line = text.replace(/\s+/g, ' ').trim();

    return line.length > 70 ? `${line.slice(0, 70)}…` : line;
};

/** Local files to attach: the media's own file, or a download of its URL. */
const mediaFiles = async (post: AssistedPost): Promise<string[]> => {
    let downloadDir: string | null = null;
    const files: string[] = [];

    for (const [index, item] of post.media.entries()) {
        if (item.path) {
            files.push(item.path);
            continue;
        }

        downloadDir ??= mkdtempSync(join(tmpdir(), 'x-assist-'));
        const response = await fetch(item.url);

        if (!response.ok) {
            throw new Error(
                `Could not download media ${index + 1} (${response.status}).`,
            );
        }

        const extension =
            (item.mime_type ?? 'image/jpeg').split('/')[1] ?? 'bin';
        const file = join(downloadDir, `media-${index + 1}.${extension}`);
        writeFileSync(file, Buffer.from(await response.arrayBuffer()));
        files.push(file);
    }

    return files;
};

/**
 * Playwright's Firefox (it cannot drive the Firefox app you browse with), in
 * its own profile: sign in to X in it once and the session is kept.
 */
const launch = async (): Promise<BrowserContext> => {
    mkdirSync(PROFILE_DIR, { recursive: true });

    try {
        return await firefox.launchPersistentContext(PROFILE_DIR, {
            headless: false,
            viewport: null,
        });
    } catch (error) {
        throw new Error(
            `Firefox did not start (${(error as Error).message.split('\n')[0]}). Install it with: npx playwright install firefox`,
        );
    }
};

let browser: BrowserContext | null = null;

/** One Firefox window, reopened if you quit it. */
const openBrowser = async (): Promise<BrowserContext> => {
    if (browser) {
        return browser;
    }

    const context = await launch();
    context.on('close', () => {
        browser = null;
    });
    browser = context;

    return context;
};

/** Waits until the composer is on screen, giving you time to sign in first. */
const waitForComposer = async (page: Page): Promise<boolean> => {
    const deadline = Date.now() + SIGN_IN_MINUTES * 60_000;
    let askedToSignIn = false;

    while (Date.now() < deadline && !page.isClosed()) {
        if (
            await page
                .locator(TEXTAREA)
                .first()
                .isVisible()
                .catch(() => false)
        ) {
            return true;
        }

        const url = page.url();

        if (
            url.includes('/login') ||
            url.includes('/i/flow/') ||
            url.includes('/i/jf/onboarding')
        ) {
            if (!askedToSignIn) {
                log(
                    'Sign in to X in the window. The post will be filled in after that.',
                );
                askedToSignIn = true;
            }
        } else if (askedToSignIn && !url.includes('/compose/')) {
            await page.goto(COMPOSE_URL).catch(() => undefined);
        }

        await sleep(2000);
    }

    return false;
};

const fillComposer = async (page: Page, post: AssistedPost): Promise<void> => {
    const textarea = page.locator(TEXTAREA).first();

    if (post.text !== '') {
        await textarea.click();
        await page.keyboard.insertText(post.text);

        if ((await textarea.innerText()).trim() === '') {
            await textarea.pressSequentially(post.text, { delay: 5 });
        }
    }

    const files = await mediaFiles(post);

    if (files.length > 0) {
        await page.locator(FILE_INPUT).first().setInputFiles(files);
    }
};

/** The newest post on your profile, when X closed the composer without a toast. */
const latestPostUrl = async (
    page: Page,
    handle: string,
): Promise<string | null> => {
    await page.goto(`https://x.com/${handle}`);
    await page
        .locator('article')
        .first()
        .waitFor({ timeout: 20_000 })
        .catch(() => undefined);

    for (const article of await page.locator('article').all()) {
        if (
            (await article.locator('[data-testid="socialContext"]').count()) > 0
        ) {
            continue; // pinned or reposted
        }

        const href = await article
            .locator(`a[href*="/${handle}/status/"]`)
            .first()
            .getAttribute('href')
            .catch(() => null);

        if (href) {
            return new URL(href, 'https://x.com').toString();
        }
    }

    return null;
};

/** Waits for you to press Post (or close the window) and reports what happened. */
const waitForPost = async (
    page: Page,
    post: AssistedPost,
): Promise<Outcome> => {
    const deadline = Date.now() + POST_MINUTES * 60_000;
    let composerGoneSince: number | null = null;

    while (Date.now() < deadline) {
        if (page.isClosed()) {
            return { kind: 'closed' };
        }

        const toastHref = await page
            .locator(SENT_TOAST_LINK)
            .first()
            .getAttribute('href', { timeout: 500 })
            .catch(() => null);

        if (toastHref) {
            return {
                kind: 'posted',
                url: new URL(toastHref, 'https://x.com').toString(),
            };
        }

        const composerOpen = await page
            .locator(TEXTAREA)
            .first()
            .isVisible()
            .catch(() => false);

        if (composerOpen) {
            composerGoneSince = null;
        } else {
            composerGoneSince ??= Date.now();

            if (Date.now() - composerGoneSince > 6000) {
                const url = await latestPostUrl(page, post.handle);

                return url ? { kind: 'posted', url } : { kind: 'closed' };
            }
        }

        await sleep(1000);
    }

    return { kind: 'timed_out' };
};

const clipboard = (): string => {
    try {
        return execFileSync('pbpaste', { encoding: 'utf8' }).trim();
    } catch {
        return '';
    }
};

/** The status link of a post by this handle, if one is on the clipboard. */
const statusLinkOnClipboard = (handle: string): string | null => {
    const match = clipboard().match(
        new RegExp(
            `https?://(?:www\\.)?(?:x|twitter)\\.com/${handle}/status/\\d+`,
            'i',
        ),
    );

    return match ? match[0].replace('twitter.com', 'x.com') : null;
};

/**
 * Hands the post to the Firefox you already use: X's compose page with the
 * text filled in, and the media in a Finder window to drag in. Waits for the
 * post's link on the clipboard.
 */
const handOff = async (post: AssistedPost): Promise<Outcome> => {
    const folder = join(OUTBOX_DIR, post.id);
    rmSync(folder, { recursive: true, force: true });
    mkdirSync(folder, { recursive: true });

    const files = await mediaFiles(post);
    files.forEach((file, index) => {
        const extension = file.split('.').pop() ?? 'bin';
        copyFileSync(file, join(folder, `${index + 1}.${extension}`));
    });

    // The downloads were only a stop on the way to the numbered folder.
    files
        .filter((file) => file.startsWith(tmpdir()))
        .forEach((file) =>
            rmSync(dirname(file), { recursive: true, force: true }),
        );

    const alreadyCopied = statusLinkOnClipboard(post.handle);
    const intent = `${INTENT_URL}?text=${encodeURIComponent(post.text)}`;
    execFileSync('open', ['-a', 'Firefox', intent]);

    if (files.length > 0) {
        execFileSync('open', [folder]);
    }

    log(
        `Opened in your Firefox for @${post.handle}: “${preview(post.text)}”.` +
            (files.length > 0
                ? ` Drag the ${files.length} files from the Finder window in, in order (1 first).`
                : '') +
            ' Press Post, then copy the post’s link (Share > Copy link) to finish.',
    );

    const deadline = Date.now() + POST_MINUTES * 60_000;

    while (Date.now() < deadline) {
        const link = statusLinkOnClipboard(post.handle);

        if (link && link !== alreadyCopied) {
            rmSync(folder, { recursive: true, force: true });

            return { kind: 'posted', url: link };
        }

        await sleep(2000);
    }

    rmSync(folder, { recursive: true, force: true });

    return { kind: 'timed_out' };
};

const assist = async (
    context: BrowserContext,
    post: AssistedPost,
): Promise<Outcome> => {
    const page = await context.newPage();
    await page.bringToFront();
    await page.goto(COMPOSE_URL);

    if (!(await waitForComposer(page))) {
        const closedByYou = page.isClosed();
        await page.close().catch(() => undefined);

        return closedByYou ? { kind: 'closed' } : { kind: 'timed_out' };
    }

    await fillComposer(page, post);
    log(
        `Ready on X for @${post.handle}: “${preview(post.text)}”. Check it and press Post.`,
    );

    const outcome = await waitForPost(page, post);
    await page.close().catch(() => undefined);

    return outcome;
};

const main = async (): Promise<void> => {
    log(
        `Watching for X posts to publish (${MODE === 'handoff' ? 'opens them in your Firefox' : 'automated Firefox'}). Checking every ${POLL_SECONDS} seconds; press Ctrl+C to stop.`,
    );

    const waitUntil = new Map<string, number>();
    const timedOut = new Map<string, number>();

    for (;;) {
        let posts: AssistedPost[] = [];

        try {
            posts = pendingPosts().filter(
                (post) => (waitUntil.get(post.id) ?? 0) <= Date.now(),
            );
        } catch (error) {
            log(
                `Could not read the posts waiting for you: ${(error as Error).message}`,
            );
        }

        for (const post of posts) {
            try {
                const outcome =
                    MODE === 'handoff'
                        ? await handOff(post)
                        : await assist(await openBrowser(), post);

                if (outcome.kind === 'posted') {
                    artisan('x-assist:complete', post.id, outcome.url);
                    log(`Published: ${outcome.url}`);
                } else if (outcome.kind === 'closed') {
                    artisan('x-assist:cancel', post.id);
                    log(
                        'Window closed without posting. The post is marked as not posted; retry it in X-board when you want.',
                    );
                } else if (
                    (timedOut.get(post.id) ?? 0) + 1 >=
                    MAX_TIMED_OUT_HANDOFFS
                ) {
                    artisan('x-assist:cancel', post.id);
                    timedOut.delete(post.id);
                    log(
                        `No post after ${MAX_TIMED_OUT_HANDOFFS} tries. The post is marked as not posted; retry it in X-board when you want.`,
                    );
                } else {
                    timedOut.set(post.id, (timedOut.get(post.id) ?? 0) + 1);
                    waitUntil.set(
                        post.id,
                        Date.now() + RETRY_AFTER_MINUTES * 60_000,
                    );
                    log(
                        `No post after ${POST_MINUTES} minutes. It will open again in ${RETRY_AFTER_MINUTES} minutes.`,
                    );
                }
            } catch (error) {
                waitUntil.set(
                    post.id,
                    Date.now() + RETRY_AFTER_MINUTES * 60_000,
                );
                log(
                    `Something went wrong with this post: ${(error as Error).message}. It will open again in ${RETRY_AFTER_MINUTES} minutes.`,
                );
            }
        }

        await sleep(POLL_SECONDS * 1000);
    }
};

await main();
