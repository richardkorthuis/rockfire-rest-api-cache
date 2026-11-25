/**
 * Dev-only screenshot generator for the WordPress.org listing.
 *
 * It never runs in CI and never ships: the /scripts folder stays out of the release zip (.distignore), and no
 * package.json, lockfile or node_modules is committed, because Playwright is fetched by npx.
 *
 * It seeds the local site with WP-CLI and anonymous REST requests, signs in to wp-admin and writes five numbered
 * PNGs of the plugin's admin pages (1280 x 800) to .wordpress-org/screenshot-1.png to screenshot-5.png, in the
 * order of the readme captions. The site's hostname and title are blurred wherever they show. Deactivate unrelated
 * plugins (such as WooCommerce, which also brings Jetpack routes) before a run, so their menus and routes stay out.
 *
 * One-time browser download, on the machine that regenerates the screenshots:
 *
 *   npx --yes playwright@1.55.0 install chromium
 *
 * Run, from the repository root, with the environment below set:
 *
 *   npx --yes --package=playwright@1.55.0 node scripts/screenshots.mjs
 *
 * Environment
 *   RFRAC_SHOT_URL        required, the site address (a trailing slash is trimmed)
 *   RFRAC_SHOT_USER       required, an administrator login
 *   RFRAC_SHOT_PASSWORD   required, the password of that administrator (never printed)
 *   RFRAC_SHOT_WP         optional, the WP-CLI command, default wp (on Windows it runs through the shell: quote a
 *                         path that contains spaces)
 *   RFRAC_SHOT_WP_PATH    optional, the WordPress root, passed to WP-CLI as --path=<value>
 *
 * Node trusts only its bundled certificate authorities: for a site on a locally trusted certificate, set
 * NODE_EXTRA_CA_CERTS or NODE_USE_SYSTEM_CA=1 so the seed requests succeed.
 *
 * Exit codes: 0 done, 1 failure (including an admin page that did not load), 2 missing environment variable or
 * Playwright.
 */

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const RUN_COMMAND = 'npx --yes --package=playwright@1.55.0 node scripts/screenshots.mjs';
const MIN_POSTS = 8;
const VIEWPORT = { width: 1280, height: 800 };

const siteUrl = (process.env.RFRAC_SHOT_URL ?? '').replace(/\/+$/, '');
const siteUser = process.env.RFRAC_SHOT_USER ?? '';
const sitePass = process.env.RFRAC_SHOT_PASSWORD ?? '';
const wpCommand = process.env.RFRAC_SHOT_WP || 'wp';
const wpPath = process.env.RFRAC_SHOT_WP_PATH ?? '';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outDir = path.join(repoRoot, '.wordpress-org');

/**
 * The id the plugin gives a route pattern in its admin URLs: the first 12 hex characters of its md5.
 *
 * @param {string} route Route pattern exactly as registered.
 * @return {string} 12 hex characters.
 */
function routeId(route) {
	return createHash('md5').update(route).digest('hex').slice(0, 12);
}

// The admin pages to capture, in the order of the readme captions.
const SHOTS = [
	'admin.php?page=rfrac-caches',
	'admin.php?page=rfrac-routes&namespace=' + encodeURIComponent('wp/v2'),
	'admin.php?page=rfrac-routes&action=edit&route_id=' + routeId('/wp/v2/posts'),
	'admin.php?page=rfrac-settings',
	'admin.php?page=rfrac-tools',
];

// The anonymous REST reads that fill the cache, each requested twice: a miss, then a hit.
const SEED_PATHS = [
	'/wp-json/wp/v2/posts',
	'/wp-json/wp/v2/posts?per_page=3',
	'/wp-json/wp/v2/pages',
	'/wp-json/wp/v2/categories',
	'/wp-json/wp/v2/tags',
	'/wp-json/wp/v2/users',
	'/wp-json/wp/v2/types',
];

/**
 * A failure with a message for the console and the exit code to end with.
 */
class Failure extends Error {
	/**
	 * @param {string} message  What went wrong.
	 * @param {number} exitCode The process exit code.
	 */
	constructor(message, exitCode = 1) {
		super(message);
		this.exitCode = exitCode;
	}
}

/**
 * Checks the environment before anything runs.
 *
 * @return {void}
 */
function checkEnvironment() {
	const required = [
		['RFRAC_SHOT_URL', siteUrl],
		['RFRAC_SHOT_USER', siteUser],
		['RFRAC_SHOT_PASSWORD', sitePass],
	];
	const missing = required.filter(([, value]) => value === '').map(([name]) => name);
	if (missing.length > 0) {
		throw new Failure(missing.map((name) => `missing environment variable ${name}`).join('\n'), 2);
	}
}

/**
 * Loads the chromium launcher of Playwright.
 *
 * A bare import resolves from the repository, which has no node_modules. Under npx the package sits in a cache
 * folder whose node_modules/.bin is on the PATH, so that is searched next.
 *
 * @return {Promise<object>} The Playwright chromium launcher.
 */
async function loadChromium() {
	let mod = null;
	try {
		mod = await import('playwright');
	} catch (error) {
		if (error?.code !== 'ERR_MODULE_NOT_FOUND') {
			throw error;
		}
	}

	if (mod === null) {
		const entries = (process.env.PATH ?? '').split(path.delimiter);
		for (const entry of entries) {
			if (!entry.replace(/\\/g, '/').replace(/\/+$/, '').endsWith('node_modules/.bin')) {
				continue;
			}
			try {
				const resolved = createRequire(path.join(entry, 'noop.js')).resolve('playwright');
				mod = await import(pathToFileURL(resolved).href);
				break;
			} catch {
				// Not here: try the next PATH entry.
			}
		}
	}

	const chromium = mod?.chromium ?? mod?.default?.chromium;
	if (!chromium) {
		throw new Failure(`playwright is not available. Run: ${RUN_COMMAND}`, 2);
	}

	return chromium;
}

/**
 * Runs one WP-CLI command with a fixed argument array.
 *
 * On Windows the command is usually a .bat file, so it runs through the shell with every argument double-quoted;
 * elsewhere no shell is involved. Node 24 and later prints a DEP0190 warning for the shell form: it is expected.
 *
 * @param {string[]} args The WP-CLI arguments.
 * @return {string} What the command wrote to stdout.
 */
function wp(args) {
	const all = wpPath === '' ? args : [`--path=${wpPath}`, ...args];
	const options = { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] };
	if (process.platform === 'win32') {
		return execFileSync(wpCommand, all.map((arg) => `"${arg}"`), { ...options, shell: true });
	}

	return execFileSync(wpCommand, all, options);
}

/**
 * Tops the site up to enough posts, empties the cache and fills it with anonymous REST reads.
 *
 * @return {Promise<void>}
 */
async function seed() {
	const count = Number.parseInt(
		wp(['post', 'list', '--post_type=post', '--post_status=publish', '--format=count']).trim(),
		10
	);
	if (Number.isNaN(count)) {
		throw new Failure('wp post list did not return a post count');
	}
	if (count < MIN_POSTS) {
		console.log(`generating ${MIN_POSTS - count} posts`);
		wp(['post', 'generate', `--count=${MIN_POSTS - count}`, '--post_status=publish']);
	}

	console.log('flushing the cache');
	wp(['rfrac', 'flush', '--all']);

	console.log('requesting the REST API');
	for (const route of SEED_PATHS) {
		for (let request = 1; request <= 2; request++) {
			const response = await fetch(siteUrl + route);
			await response.arrayBuffer();
			if (request === 1 && response.status !== 200) {
				throw new Failure('the site must serve /wp-json/ (pretty permalinks)');
			}
		}
	}
}

/**
 * Signs in at wp-login.php.
 *
 * @param {object} page A Playwright page.
 * @return {Promise<void>}
 */
async function signIn(page) {
	await page.goto(`${siteUrl}/wp-login.php`);
	await page.fill('#user_login', siteUser);
	await page.fill('#user_pass', sitePass);
	await page.click('#wp-submit');
	await page.waitForFunction(
		() => window.location.pathname.includes('/wp-admin/') || document.querySelector('#login_error') !== null,
		undefined,
		{ timeout: 30000 }
	);
	if (await page.locator('#login_error').isVisible()) {
		throw new Failure('sign-in failed for RFRAC_SHOT_USER');
	}
}

/**
 * Checks that a navigation ended on the admin page that was asked for, before it is captured.
 *
 * A missing response, a status outside 2xx, a redirect away from the requested page (to wp-login.php, or to another
 * admin page), WordPress's die page (body#error-page) and a page without the admin content area all fail. Pure, so it
 * can be tested without a browser.
 *
 * @param {object}      page                  What the browser reported for the navigation.
 * @param {string}      page.requested        The URL that was asked for.
 * @param {string}      page.finalUrl         The URL the browser ended on.
 * @param {number|null} page.status           The HTTP status of the final response, null when there was none.
 * @param {boolean}     page.hasAdminContent  Whether #wpbody-content is on the page.
 * @param {boolean}     page.isErrorPage      Whether body#error-page is on the page.
 * @return {void}
 * @throws {Failure} Exit code 1, naming the requested URL.
 */
export function assertAdminPage({ requested, finalUrl, status, hasAdminContent, isErrorPage }) {
	const fail = (reason) => {
		throw new Failure(`admin page did not load: ${requested} (${reason})`);
	};

	if (status === null || status === undefined) {
		fail('no response');
	}
	if (status < 200 || status > 299) {
		fail(`HTTP ${status}`);
	}

	const wanted = new URL(requested);
	const final = new URL(finalUrl);
	let sameQuery = true;
	for (const [name, value] of wanted.searchParams) {
		if (final.searchParams.get(name) !== value) {
			sameQuery = false;
		}
	}
	if (final.origin !== wanted.origin || final.pathname !== wanted.pathname || !sameQuery) {
		fail(`redirected to ${finalUrl}`);
	}

	if (isErrorPage) {
		fail('WordPress error page');
	}
	if (!hasAdminContent) {
		fail('#wpbody-content is missing');
	}
}

/**
 * Blurs every place the page shows the site's hostname or title, so the screenshots do not name the local site.
 *
 * Visible text that contains one of the terms, case-insensitively, is wrapped in a blurred span; form fields whose
 * value or placeholder contains one are blurred whole.
 *
 * @param {object}   page  A Playwright page.
 * @param {string[]} terms The strings to hide, such as the hostname and the site title.
 * @return {Promise<void>}
 */
async function blurSiteIdentity(page, terms) {
	await page.evaluate((needles) => {
		const lower = needles.filter((term) => term !== '').map((term) => term.toLowerCase());
		const matches = (text) => {
			const haystack = (text ?? '').toLowerCase();
			return lower.some((term) => haystack.includes(term));
		};
		const blur = (element) => {
			element.style.setProperty('filter', 'blur(5px)', 'important');
		};

		const hits = [];
		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
		for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
			const parent = node.parentElement;
			if (parent === null || parent.closest('script, style, noscript, template, textarea') !== null) {
				continue;
			}
			if (matches(node.nodeValue)) {
				hits.push(node);
			}
		}
		for (const node of hits) {
			const span = document.createElement('span');
			span.style.display = 'inline-block';
			blur(span);
			node.replaceWith(span);
			span.appendChild(node);
		}

		for (const field of document.querySelectorAll('input, textarea')) {
			if (matches(field.value) || matches(field.getAttribute('placeholder'))) {
				blur(field);
			}
		}
	}, terms);
}

/**
 * Creates the output folder and removes the screenshots of an earlier run.
 *
 * @return {void}
 */
function prepareOutput() {
	fs.mkdirSync(outDir, { recursive: true });
	for (const name of fs.readdirSync(outDir)) {
		if (name.startsWith('screenshot-') && name.endsWith('.png')) {
			fs.unlinkSync(path.join(outDir, name));
		}
	}
}

/**
 * Seeds the site, signs in and writes the screenshots.
 *
 * @return {Promise<void>}
 */
async function main() {
	checkEnvironment();
	const chromium = await loadChromium();
	await seed();
	const hidden = [new URL(siteUrl).hostname, wp(['option', 'get', 'blogname']).trim()];

	const browser = await chromium.launch({ headless: true });
	try {
		const context = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: 1 });
		const page = await context.newPage();
		await signIn(page);
		prepareOutput();

		for (const [index, shot] of SHOTS.entries()) {
			const requested = `${siteUrl}/wp-admin/${shot}`;
			const response = await page.goto(requested, { waitUntil: 'networkidle' });
			assertAdminPage({
				requested,
				finalUrl: page.url(),
				status: response === null ? null : response.status(),
				hasAdminContent: (await page.locator('#wpbody-content').count()) > 0,
				isErrorPage: (await page.locator('body#error-page').count()) > 0,
			});
			await page.addStyleTag({ content: '.notice, .update-nag, #wpfooter { display: none !important; }' });
			await blurSiteIdentity(page, hidden);
			const file = path.join(outDir, `screenshot-${index + 1}.png`);
			await page.screenshot({ path: file, fullPage: false });
			console.log(file);
		}
	} finally {
		await browser.close();
	}
}

/**
 * Whether this file is the one node was started with, and not imported (the test imports it for assertAdminPage).
 *
 * @return {boolean} True when run directly.
 */
function isEntryPoint() {
	const entry = process.argv[1];
	if (!entry) {
		return false;
	}
	try {
		return fs.realpathSync.native(entry) === fs.realpathSync.native(fileURLToPath(import.meta.url));
	} catch {
		return false;
	}
}

if (isEntryPoint()) {
	main().catch((error) => {
		if (error instanceof Failure) {
			console.error(error.message);
			process.exitCode = error.exitCode;
			return;
		}
		console.error(error);
		process.exitCode = 1;
	});
}
